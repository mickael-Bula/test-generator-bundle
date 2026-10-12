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
    private const MAX_PHPUNIT_ATTEMPTS = 3;
    private const MAX_PHPSTAN_ATTEMPTS = 2;

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
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private readonly string $failedTestsDir = 'var/failed_tests',
        #[Autowire('%kernel.project_dir%/var/tmp')] private readonly string $tmpDir = 'var/tmp',
    ) {
        // Création automatique des dossiers s'ils n'existent pas encore sur l'hôte
        if (!$this->filesystem->exists($this->tmpDir)) {
            $this->filesystem->mkdir($this->tmpDir);
        }
        if (!$this->filesystem->exists($this->failedTestsDir)) {
            $this->filesystem->mkdir($this->failedTestsDir);
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
        $testCode = '';
        $lastOutput = 'Aucun rapport PHPUnit généré (échec avant l\'exécution des tests).';

        // ==========================================
        // PHASE 1 : SYNTAXE + RUNNER PHPUNIT
        // ==========================================
        $phpunitAttempt = 0;
        $isPhpUnitValid = false;

        while ($phpunitAttempt < self::MAX_PHPUNIT_ATTEMPTS) {
            ++$phpunitAttempt;

            $rawTestCode = $client->call($messages, $targetModel);
            $testCode = $this->cleanLlmOutput($rawTestCode);

            // Validation de la syntaxe PHP (nikic/php-parser)
            $syntaxResult = $this->syntaxValidator->validate($testCode);

            if (!$syntaxResult->isValid) {
                // ÉCHEC SYNTAXIQUE : résolution dynamique du Skill adapté à l'erreur de syntaxe
                $syntaxErrorOutput = sprintf(
                    "Erreur de syntaxe à la ligne %d: %s",
                    $syntaxResult->errorLine ?? 0,
                    $syntaxResult->errorMessage ?? 'Syntaxe PHP invalide'
                );

                $errorMessage = "ERREUR DE SYNTAXE PHP :\n" . $syntaxErrorOutput . "\n\n";
                $errorMessage .= "Corrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.";

                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = ['role' => 'user', 'content' => $errorMessage];

                // On relance la boucle pour la correction de la syntaxe.
                continue;
            }

            // Exécution du test PHPUnit
            $result = $this->testRunner->runTest($testCode, $className);

            $rawOutput = $result['output'] ?? '';

            // 1. Suppression des codes de coloration ANSI de PHPUnit pour fiabiliser les str_contains
            $cleanOutput = preg_replace('/\x1b\[[0-9;]*[mK]/', '', $rawOutput);
            $lastOutput = '' !== $cleanOutput ? $cleanOutput : 'Aucune sortie reçue de PHPUnit.';

            // Détection de la présence d'avertissements ou de warnings PHP dans la sortie
            $hasPhpWarnings = str_contains($lastOutput, 'PHP warning:') || str_contains($lastOutput, 'Warnings:');

            if ($result['success'] && !$hasPhpWarnings) {
                $isPhpUnitValid = true;
                break; // PHPUnit passe sans aucun warning, on avance vers PHPStan
            }

            // Si PHPUnit réussit, mais contient des warnings, on force le passage dans le Fixer
            // en transformant la sortie en rapport d'erreur exploitable par les intercepteurs.
            if ($result['success'] && $hasPhpWarnings) {
                $lastOutput = "ERREUR DE VALIDATION (AVERTISSEMENT PHP DÉTECTÉ) :\n" . $lastOutput;
            }

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
        }

        // Si PHPUnit n'a pas réussi à valider en 3 tentatives
        if (!$isPhpUnitValid) {
            $savedPath = $this->saveFailedTest($className, $testCode, $lastOutput);
            throw new TestCorrectionException(
                sprintf(
                    'Impossible de générer un test valide (PHPUnit) pour %s après %d tentatives. Sauvegardé dans : %s',
                    $className,
                    self::MAX_PHPUNIT_ATTEMPTS,
                    $savedPath)
            );
        }

        // ==========================================
        // PHASE 2 : ANALYSE STATIQUE PHPSTAN
        // ==========================================
        $phpstanAttempt = 0;

        while ($phpstanAttempt < self::MAX_PHPSTAN_ATTEMPTS) {
            ++$phpstanAttempt;

            // 2.1 Validation de la syntaxe avant d'écrire sur le disque
            $syntaxResult = $this->syntaxValidator->validate($testCode);
            if (!$syntaxResult->isValid) {
                $lastOutput = sprintf(
                    "ERREUR DE SYNTAXE PHP (pendant la phase PHPStan, ligne %d) :\n%s",
                    $syntaxResult->errorLine ?? 0,
                    $syntaxResult->errorMessage ?? 'Syntaxe invalide'
                );

                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = [
                    'role' => 'user',
                    'content' => $lastOutput . "\n\nCorrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.",
                ];

                $testCode = $client->call($messages, $targetModel);
                continue;
            }

            // Écriture du code dans un fichier temporaire pour analyse et test
            $tempFilePath = sprintf(
                '%s' . DIRECTORY_SEPARATOR . '%s_TempTest_%s.php',
                rtrim($this->tmpDir, '/\\'),
                $className,
                uniqid('', true)
            );

            try {
                $this->filesystem->dumpFile($tempFilePath, $testCode);

                // Analyse statique de PHPStan (seulement si la syntaxe et PHPUnit sont OK)
                $phpStanResult = $this->phpStanRunner->analyze($tempFilePath);

                if ($phpStanResult['success']) {
                    // Tout est vert !
                    return $testCode;
                }
                $lastOutput = "ERREUR D'ANALYSE STATIQUE (PHPStan) :\n" . $phpStanResult['output'];
            } finally {
                // Nettoyage systématique du fichier temporaire après chaque tentative initiée par PHPStan
                if ($this->filesystem->exists($tempFilePath)) {
                    $this->filesystem->remove($tempFilePath);
                }
            }

            // ÉCHEC DE L'ANALYSE (PHPSTAN) : on appelle le Fixer PHPUnit
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

            // Génération de la version corrigée pour la tentative PHPStan suivante
            $testCode = $client->call($messages, $targetModel);
        }

        // ÉCHEC APRÈS 3 TENTATIVES : On sauvegarde le fichier dans le répertoire dédié.
        $savedPath = $this->saveFailedTest($className, $testCode, $lastOutput);

        $message = sprintf(
            "Impossible de générer un test valide pour %s après %d tentatives. Le test défaillant a été conservé dans :\n %s",
            $className,
            self::MAX_PHPSTAN_ATTEMPTS,
            $savedPath
        );
        throw new TestCorrectionException($message);
    }

    /**
     * Récupération du prompt du Fixer et injection dynamique des Skills selon l'erreur.
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
     * Sauvegarde le test défaillant et le rapport d'erreur PHPUnit.
     */
    private function saveFailedTest(string $className, string $testCode, ?string $failureOutput): string
    {
        // Formatage du nom de fichier avec Horodatage
        $date = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
        $filename = sprintf('%s_%s_FailedTest.php', $date, $className);
        $logFilename = sprintf('%s_%s_FailedTest.log', $date, $className);

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

    /**
     * Nettoie la réponse du LLM pour extraire uniquement le code PHP valide.
     * Supprime les blocs Markdown (```php ... ```) et les backticks résiduels.
     */
    private function cleanLlmOutput(string $rawCode): string
    {
        // TODO : un bout de ce code existe déjà dans PhpCodeExtractor => à factoriser
        // 1. Découpe si le LLM a entouré le code de balises ```php ou ```
        if (preg_match('/```(?:php)?\s*(.*?)\s*```/s', $rawCode, $matches)) {
            $rawCode = $matches[1];
        }

        // 2. Supprime tout reliquat de backticks en début/fin de chaîne
        $cleanCode = trim($rawCode);
        $cleanCode = preg_replace('/^```(?:php)?/i', '', $cleanCode);
        $cleanCode = preg_replace('/```$/', '', $cleanCode);

        return trim($cleanCode);
    }
}
