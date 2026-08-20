<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;
use Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Filesystem\Filesystem;

class TestGenerator
{
    private const MAX_ATTEMPT = 3;

    /**
     * @param iterable<TestPromptBuilderInterface> $promptBuilders
     */
    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly PhpUnitTestRunner $testRunner,
        private readonly PhpSyntaxValidator $syntaxValidator,
        #[AutowireIterator('mika_test_generator.prompt_builder')] // Récupère toutes les classes portant ce tag
        private readonly iterable $promptBuilders,
        private readonly PhpStanRunner $phpStanRunner,
        private readonly Filesystem $filesystem = new Filesystem(),
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private string $failedTestsDir = '',
    ) {
        if ('' === $this->failedTestsDir) {
            $this->failedTestsDir = sys_get_temp_dir();
        }
    }

    /**
     * Retourne la première instance avec le tag répondant au type de prompt.
     */
    private function getPromptBuilder(string $type): TestPromptBuilderInterface
    {
        foreach ($this->promptBuilders as $builder) {
            if ($builder->supports($type)) {
                return $builder;
            }
        }

        throw new \InvalidArgumentException(sprintf('Aucun prompt builder trouvé pour le type "%s".', $type));
    }

    /**
     * @throws TestCorrectionException|\RuntimeException|\JsonException
     */
    public function generateForClass(
        string $classCode,
        string $filePath,
        string $fqcn,
        string $className,
        ?string $model = null,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
        ?string $type = 'unit',
        ?string $provider = null,
    ): string {
        // Sélection du builder approprié
        $builder = $this->getPromptBuilder($type);

        // Construction des messages via le builder
        $prompts = $builder->buildPrompt(
            classCode: $classCode,
            filePath: $filePath,
            fqcn: $fqcn,
            className: $className,
            methodName: $methodName,
            existingTestCode: $existingTestCode,
            specContent: $specContent,
            provider: $provider
        );

        $messages = [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']],
        ];

        // On résout le client et le modèle à l'aide de la Factory
        $client = $this->llmFactory->getClient();
        $targetModel = $model ?? $this->llmFactory->getDefaultModel();

        // Initialisation des variables d'état pour la persistance en cas d'échec
        $attempt = 0;
        $testCode = '';
        $lastOutput = 'Aucun rapport PHPUnit généré (échec avant l\'exécution des tests).';

        while ($attempt < self::MAX_ATTEMPT) {
            ++$attempt;

            $testCode = $client->call($messages, $targetModel);

            // 1. Validation de la syntaxe PHP (nikic/php-parser)
            $syntaxResult = $this->syntaxValidator->validate($testCode);

            if (!$syntaxResult->isValid) {
                // ÉCHEC SYNTAXIQUE : On informe le LLM directement sans appeler PHPUnit
                $errorMessage = sprintf(
                    "Une erreur de syntaxe PHP s'est produite à la ligne %d :\n%s\n\n" .
                    "Règles d'anti-crash :\n" .
                    "- N'échappe JAMAIS les variables avec des antislashs (écris \$this et non \\\$this).\n" .
                    '- Vérifie la fermeture de toutes les chaînes de caractères.',
                    $syntaxResult->errorLine ?? 0,
                    $syntaxResult->errorMessage ?? 'Syntaxe PHP invalide'
                );

                // On conserve la dernière erreur de syntaxe comme rapport en cas d'échec final
                $lastOutput = "ERREUR DE SYNTAXE PHP :\n" . $errorMessage;

                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = [
                    'role' => 'user',
                    'content' => $errorMessage . "\n\nCorrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.",
                ];

                // On relance la boucle pour la correction de la syntaxe.
                continue;
            }

            // Écriture du code dans un fichier temporaire pour analyse et test
            $tempFilePath = sprintf(
                '%s/%s_TempTest_%s.php',
                rtrim(sys_get_temp_dir(), '/\\'),
                $className,
                uniqid('', true)
            );

            try {
                $this->filesystem->dumpFile($tempFilePath, $testCode);

                // Analyse statique PHPStan
                $phpStanResult = $this->phpStanRunner->analyze($tempFilePath);

                if (!$phpStanResult['success']) {
                    $lastOutput = "ERREUR D'ANALYSE STATIQUE (PHPStan) :\n" . $phpStanResult['output'];

                    $this->prepareFixerIteration(
                        $messages,
                        $testCode,
                        $lastOutput,
                        $classCode,
                        $filePath,
                        $fqcn,
                        $className,
                        $provider
                    );

                    // On relance la boucle pour la correction de PHPStan.
                    continue;
                }

                // Exécution du test PHPUnit (seulement si la syntaxe et PHPStan sont OK)
                $result = $this->testRunner->runTest($testCode, $className);
                $lastOutput = '' !== $result['output'] ? $result['output'] : 'Aucune sortie reçue de PHPUnit.';

                if ($result['success']) {
                    return $testCode;
                }

                // ÉCHEC DU TEST (Erreurs d'assertions ou d'exécution PHPUnit) : on appelle le Fixer PHPUnit
                $this->prepareFixerIteration(
                    $messages,
                    $testCode,
                    $lastOutput,
                    $classCode,
                    $filePath,
                    $fqcn,
                    $className,
                    $provider
                );
            } finally {
                // Nettoyage systématique du fichier temporaire après chaque tentative
                if ($this->filesystem->exists($tempFilePath)) {
                    $this->filesystem->remove($tempFilePath);
                }
            }
        }

        // ÉCHEC APRÈS 3 TENTATIVES : On sauvegarde le fichier dans le répertoire dédié.
        $savedPath = $this->saveFailedTest($className, $testCode, $lastOutput);

        $message = sprintf(
            'Impossible de générer un test valide pour %s après %d tentatives. Le test défaillant a été conservé dans : %s',
            $className,
            self::MAX_ATTEMPT,
            $savedPath
        );
        throw new TestCorrectionException($message);
    }

    /**
     * Récupération du prompt du Fixer.
     * Le tableau $messages étant passé par référence, les modifications y sont directement appliquées.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function prepareFixerIteration(
        array &$messages,
        string $testCode,
        string $lastOutput,
        string $classCode,
        string $filePath,
        string $fqcn,
        string $className,
        ?string $provider,
    ): void {
        $fixerBuilder = $this->getPromptBuilder('fixer');

        $fixerPrompts = $fixerBuilder->buildPrompt(
            classCode: $classCode,
            filePath: $filePath,
            fqcn: $fqcn,
            className: $className,
            existingTestCode: $testCode,
            specContent: $lastOutput,
            provider: $provider
        );

        // Mettre à jour le prompt système et enrichir l'historique
        $messages[0] = ['role' => 'system', 'content' => $fixerPrompts['system']];
        $messages[] = ['role' => 'assistant', 'content' => $testCode];
        $messages[] = ['role' => 'user', 'content' => $fixerPrompts['user']];
    }

    public function replaceDynamicHeadersInExistingTestCode(
        string $existingTestCode,
        string $targetNamespace,
        string $className,
    ): string {
        return str_replace(
            [
                sprintf('namespace %s;', $targetNamespace),
                sprintf('class %sTest', $className),
            ],
            [
                'namespace App\Tests\Dynamic;',
                sprintf('class %sDynamicTest', $className),
            ],
            $existingTestCode
        );
    }

    public function replaceDynamicHeadersInTestCode(string $testCode, string $targetNamespace, string $className): string
    {
        return str_replace(
            [
                'namespace App\Tests\Dynamic;',
                sprintf('class %sDynamicTest', $className),
            ],
            [
                sprintf('namespace %s;', $targetNamespace),
                sprintf('class %sTest', $className),
            ],
            $testCode
        );
    }

    /**
     * Sauvegarde le test défaillant et du rapport d'erreur PHPUnit.
     */
    private function saveFailedTest(string $className, string $testCode, ?string $failureOutput): string
    {
        // Formatage du nom de fichier avec Horodatage
        $date = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
        $filename = sprintf('%s_%sFailedTest.php', $date, $className);
        $logFilename = sprintf('%s_%sFailedTest.log', $date, $className);

        // Normalisation des séparateurs de dossiers selon l'OS (DIRECTORY_SEPARATOR)
        $normalizedDir = rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->failedTestsDir),
            DIRECTORY_SEPARATOR
        );

        $targetPath = $normalizedDir . DIRECTORY_SEPARATOR . $filename;
        $logPath = $normalizedDir . DIRECTORY_SEPARATOR . $logFilename;

        // Écriture du fichier de test non fonctionnel
        $this->filesystem->dumpFile($targetPath, $testCode);

        // Écriture du rapport PHPUnit correspondant pour consultation rapide
        if (null !== $failureOutput) {
            $this->filesystem->dumpFile($logPath, $failureOutput);
        }

        return $targetPath;
    }
}
