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

final class TestGeneratorV2
{
    private const MAX_PHPUNIT_ATTEMPTS = 3;
    private const MAX_PHPSTAN_ATTEMPTS = 2;

    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly PhpUnitTestRunner $testRunner,
        private readonly PhpSyntaxValidator $syntaxValidator,
        private readonly PhpStanRunner $phpStanRunner,
        private readonly Filesystem $filesystem = new Filesystem(),
        #[Autowire('%kernel.project_dir%/var/tmp')] private readonly string $tmpDir = 'var/tmp',
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private readonly string $failedTestsDir = 'var/failed_tests',
        #[AutowireIterator('mika_test_generator.prompt_builder')] private readonly iterable $promptBuilders, // Récupère toutes les classes portant ce tag
    ) {
        if (!$this->filesystem->exists($this->tmpDir)) {
            $this->filesystem->mkdir($this->tmpDir);
        }
        if (!$this->filesystem->exists($this->failedTestsDir)) {
            $this->filesystem->mkdir($this->failedTestsDir);
        }
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
        string $type = 'unit_v2',
        ?string $provider = null,
    ): string {
        // MAPPING VERS LES BUILDERS V2 (PHASE DE DEV)
        if ($type === 'unit') {
            $type = 'unit_v2';
        } elseif ($type === 'functional') {
            $type = 'functional_v2'; // Si une version 2 fonctionnelle est développée plus tard
        }

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

        $client = $this->llmFactory->getClient();
        $targetModel = $model ?? $this->llmFactory->getDefaultModel();

        // Construction du prompt
        $messages = [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']],
        ];

        $testCode = '';
        $lastOutput = 'Aucun rapport PHPUnit généré.';

        // ==========================================
        // PHASE 1 : SYNTAXE + RUNNER PHPUNIT
        // ==========================================
        $phpunitAttempt = 0;
        $isPhpUnitValid = false;

        while ($phpunitAttempt < self::MAX_PHPUNIT_ATTEMPTS) {
            ++$phpunitAttempt;

            $rawTestCode = $client->call($messages, $targetModel);
            $testCode = $this->cleanLlmOutput($rawTestCode);

            // Validation de la syntaxe PHP
            $syntaxResult = $this->syntaxValidator->validate($testCode);

            if (!$syntaxResult->isValid) {$syntaxErrorOutput = sprintf(
                "Erreur de syntaxe à la ligne %d: %s",
                $syntaxResult->errorLine ?? 0,
                $syntaxResult->errorMessage ?? 'Syntaxe PHP invalide'
            );

                $errorMessage = "ERREUR DE SYNTAXE PHP :\n" . $syntaxErrorOutput . "\n\n"
                    . "Corrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.";

                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = ['role' => 'user', 'content' => $errorMessage];
                continue;
            }

            // Exécution du test PHPUnit
            $result =$this->testRunner->runTest($testCode, $className);
            $rawOutput =$result['output'] ?? '';

            // Suppression des codes ANSI
            $cleanOutput = preg_replace('/\x1b\[[0-9;]*[mK]/', '', $rawOutput);
            $lastOutput = '' !== $cleanOutput ? $cleanOutput : 'Aucune sortie reçue de PHPUnit.';

            $hasPhpWarnings = str_contains($lastOutput, 'PHP warning:') || str_contains($lastOutput, 'Warnings:');

            if ($result['success'] && !$hasPhpWarnings) {
                $isPhpUnitValid = true;
                break; // PHPUnit passe sans aucune erreur ni warning
            }

            if ($result['success'] && $hasPhpWarnings) {
                $lastOutput = "ERREUR DE VALIDATION (AVERTISSEMENT PHP DÉTECTÉ) :\n" . $lastOutput;
            }

            // Utilisation du registre d'intercepteurs modulaires
            $this->prepareFixerIterationV2($messages, $testCode, $lastOutput, $classCode, $filePath, $fqcn, $className, $provider);
        }

        if (!$isPhpUnitValid) {
            $savedPath = $this->saveFailedTest($className, $testCode, $lastOutput);
            throw new TestCorrectionException(
                sprintf(
                    'Impossible de générer un test valide (PHPUnit) pour %s. Sauvegardé dans : %s',
                    $className,
                    $savedPath
                )
            );
        }

        // ==========================================
        // PHASE 2 : ANALYSE STATIQUE PHPSTAN
        // ==========================================
        $phpstanAttempt = 0;

        while ($phpstanAttempt < self::MAX_PHPSTAN_ATTEMPTS) {
            ++$phpstanAttempt;

            $syntaxResult = $this->syntaxValidator->validate($testCode);
            if (!$syntaxResult->isValid) {
                $lastOutput = sprintf(
                "ERREUR DE SYNTAXE PHP (PHPStan, ligne %d) :\n%s",
                $syntaxResult->errorLine ?? 0,
                $syntaxResult->errorMessage ?? 'Syntaxe invalide'
            );

                $messages[] = ['role' => 'assistant', 'content' =>$testCode];
                $messages[] = [
                    'role' => 'user',
                    'content' =>$lastOutput . "\n\nCorrige immédiatement la syntaxe et renvoie le code complet."
                ];

                $testCode =$client->call($messages,$targetModel);
                continue;
            }

            $tempFilePath = sprintf(
                '%s' . DIRECTORY_SEPARATOR . '%s_TempTestV2_%s.php',
                rtrim($this->tmpDir, '/\\'),$className,
                uniqid('', true)
            );

            try {
                $this->filesystem->dumpFile($tempFilePath, $testCode);
                $phpStanResult = $this->phpStanRunner->analyze($tempFilePath);

                if ($phpStanResult['success']) {
                    return $testCode;
                }
                $lastOutput = "ERREUR D'ANALYSE STATIQUE (PHPStan) :\n" . $phpStanResult['output'];
            } finally {
                if ($this->filesystem->exists($tempFilePath)) {
                    $this->filesystem->remove($tempFilePath);
                }
            }

            $this->prepareFixerIterationV2($messages, $testCode, $lastOutput, $classCode, $filePath, $fqcn, $className, $provider);
            $testCode = $client->call($messages, $targetModel);
        }

        $savedPath = $this->saveFailedTest($className, $testCode,$lastOutput);
        throw new TestCorrectionException(sprintf(
            'Échec PHPStan pour %s après %d tentatives. Sauvegardé dans : %s',
            $className,
            self::MAX_PHPSTAN_ATTEMPTS,
            $savedPath
        ));
    }

    /**
     * Interroge le registre d'intercepteurs pour enrichir le message du Fixer de manière modulaire.
     */
    private function prepareFixerIterationV2(
        array &$messages,
        string $testCode,
        string $lastOutput,
        string $classCode,
        string $filePath,
        string $fqcn,
        string $className,
        ?string $provider,
    ): void {
        // Utilise le nouveau PromptBuilder V2 du Fixer
        $fixerBuilder = $this->getPromptBuilder('fixer_v2');

        $fixerPrompts = $fixerBuilder->buildPrompt(
            classCode: $classCode,
            filePath: $filePath,
            fqcn: $fqcn,
            className: $className,
            existingTestCode: $testCode,
            specContent: $lastOutput,
            provider: $provider
        );

        $messages[0] = ['role' => 'system', 'content' => $fixerPrompts['system']];
        $messages[] = ['role' => 'assistant', 'content' => $testCode];
        $messages[] = ['role' => 'user', 'content' => $fixerPrompts['user']];
    }

    /**
     * Méthode renommant les namespaced de la classe de test pour pointer vers le dossier temporaire
     */
    public function replaceDynamicHeadersInExistingTestCode(string $existingTestCode, string $targetNamespace, string $className): string
    {
        return str_replace(
            [sprintf('namespace %s;', $targetNamespace), sprintf('class %sTest', $className)],
            ['namespace App\Tests\Dynamic;', sprintf('class %sDynamicTest', $className)],
            $existingTestCode
        );
    }

    /**
     * Méthode renommant les namespaces de la classe de test pour pointer vers le dossier 'tests'
     */
    public function replaceDynamicHeadersInTestCode(string $testCode, string $targetNamespace, string $className): string
    {
        return str_replace(
            ['namespace App\Tests\Dynamic;', sprintf('class %sDynamicTest', $className)],
            [sprintf('namespace %s;', $targetNamespace), sprintf('class %sTest', $className)],
            $testCode
        );
    }

    /**
     * Copie dans le dossier 'var' les sorties en erreur générées par le LLM afin d'en permettre l'analyse
     */
    private function saveFailedTest(string $className, string $testCode, ?string $failureOutput): string
    {
        $date = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
        $filename = sprintf('%s_%s_FailedTestV2.php', $date, $className);
        $logFilename = sprintf('%s_%s_FailedTestV2.log', $date, $className);

        $normalizedDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->failedTestsDir), DIRECTORY_SEPARATOR);
        $targetPath = $normalizedDir . DIRECTORY_SEPARATOR . $filename;
        $logPath = $normalizedDir . DIRECTORY_SEPARATOR . $logFilename;

        $this->filesystem->dumpFile($targetPath, $testCode);
        if (null !== $failureOutput) {
            $this->filesystem->dumpFile($logPath, $failureOutput);
        }

        return $targetPath;
    }

    private function cleanLlmOutput(string $rawCode): string
    {
        if (preg_match('/```(?:php)?\s*(.*?)\s*```/s', $rawCode, $matches)) {
            $rawCode = $matches[1];
        }
        $cleanCode = trim($rawCode);
        $cleanCode = preg_replace('/^```(?:php)?/i', '', $cleanCode);
        $cleanCode = preg_replace('/```$/', '', $cleanCode);

        return trim($cleanCode);
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
}