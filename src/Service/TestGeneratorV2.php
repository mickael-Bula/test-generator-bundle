<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Interceptor\Support\MissingClassPreFixer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockPropertyTypeFixer;
use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
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
    private const MAX_PREFIX_PASSES = 5;

    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly PhpUnitTestRunner $testRunner,
        private readonly PhpSyntaxValidator $syntaxValidator,
        private readonly PhpStanRunner $phpStanRunner,
        private readonly PreFixerChain $preFixer,
        #[AutowireIterator('mika_test_generator.prompt_builder')] private readonly iterable $promptBuilders, // Récupère toutes les classes portant ce tag
        #[Autowire('%kernel.project_dir%/var/tmp')] private readonly string $tmpDir = 'var/tmp',
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private readonly string $failedTestsDir = 'var/failed_tests',
        private readonly Filesystem $filesystem = new Filesystem(),
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
        ?string $startFromTestCode = null,
    ): string {
        $client = $this->llmFactory->getClient();
        $targetModel = $model ?? $this->llmFactory->getDefaultModel();

        // Si une fixture de code en erreur est fournie, le prompt du fixer devient déterministe
        if (null !== $startFromTestCode) {
            // Reprise d'un test existant : le prompt du fixer remplacera ce message au premier échec
            $messages = [['role' => 'system', 'content' => '']];
        } else {
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

            // Construction du prompt
            $messages = [
                ['role' => 'system', 'content' => $prompts['system']],
                ['role' => 'user', 'content' => $prompts['user']],
            ];
        }

        $pending = $startFromTestCode; // code à évaluer au premier tour, sans appel LLM
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
            $testCode = $pending ?? $this->cleanLlmOutput($rawTestCode);
            $pending = null; // On s'assure qu'aucune fixture n'est réutilisée dans la boucle

            // Validation de la syntaxe PHP
            $syntaxResult = $this->syntaxValidator->validate($testCode);

            if (!$syntaxResult->isValid) {
                $syntaxErrorOutput = sprintf(
                "Erreur de syntaxe à la ligne %d: %s",
                $syntaxResult->errorLine ?? 0,
                $syntaxResult->errorMessage ?? 'Syntaxe PHP invalide'
            );

                $errorMessage = "ERREUR DE SYNTAXE PHP :\n" . $syntaxErrorOutput . "\n\n"
                    . "Corrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.";

                // On conserve le message d'erreur pour les logs
                $lastOutput = $errorMessage;

                // On met à jour le prompt
                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = ['role' => 'user', 'content' => $errorMessage];

                continue;
            }

            // Exécution du test PHPUnit avec tentative de correction statique des namespaces
            $run = $this->runWithPreFix($testCode, $className);
            $testCode = $run['code'];
            $lastOutput = $run['output'];

            if ($run['valid']) {
                $isPhpUnitValid = true;
                break; // PHPUnit passe sans aucune erreur ni warning
            }

            // Utilisation du registre d'intercepteurs modulaires
            $this->prepareFixerIterationV2(
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
        $greenCode = $testCode;   // dernière version validée par PHPUnit (fin de phase 1)
        $greenReport = null;      // rapport PHPStan de cette version
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

                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = [
                    'role' => 'user',
                    'content' => $lastOutput . "\n\nCorrige immédiatement la syntaxe et renvoie le code complet."
                ];

                // Si le nombre maximal de tentatives a été atteint, on sort de la boucle.
                if ($phpstanAttempt >= self::MAX_PHPSTAN_ATTEMPTS) {
                    break;
                }

                $rawTestCode = $client->call($messages, $targetModel);
                $testCode = $this->cleanLlmOutput($rawTestCode);
                continue;
            }

            $wasGreen = $testCode === $greenCode;
            $stan = $this->runPhpStanWithPreFix($testCode, $className);
            $testCode = $stan['code'];

            if ($wasGreen) {
                $greenReport = $stan['initial'];
            }

            if ($stan['success']) {
                if ($testCode === $greenCode) {
                    return $testCode;
                }

                $run = $this->runWithPreFix($testCode, $className);
                $testCode = $run['code'];

                if ($run['valid']) {
                    return $testCode;
                }

                $lastOutput = "RÉGRESSION PHPUNIT : ...\n\n" . $run['output'];
            } else {
                $lastOutput = "ERREUR D'ANALYSE STATIQUE (PHPStan) :\n" . $stan['output'];
            }

            // Si le nombre maximal de tentatives a été atteint, on sort de la boucle.
            if ($phpstanAttempt >= self::MAX_PHPSTAN_ATTEMPTS) {
                break;
            }

            $this->prepareFixerIterationV2(
                $messages,
                $testCode,
                $lastOutput,
                $classCode,
                $filePath,
                $fqcn,
                $className,
                $provider
            );

            $testCode = $this->cleanLlmOutput($client->call($messages, $targetModel));
        }

        $savedPath = $this->saveFailedTest($className, $greenCode, $greenReport ?? $lastOutput);
        throw new TestCorrectionException(sprintf(
            'Échec PHPStan pour %s après %d tentatives. Sauvegardé dans : %s',
            $className,
            self::MAX_PHPSTAN_ATTEMPTS,
            $savedPath
        ));
    }

    /**
     * @return array{valid: bool, output: string}
     */
    private function runPhpUnit(string $testCode, string $className): array
    {
        // Exécution du test PHPUnit
        $result = $this->testRunner->runTest($testCode, $className);

        // Suppression des codes ANSI
        $clean = preg_replace('/\x1b\[[0-9;]*[mK]/', '', $result['output'] ?? '') ?? '';
        $output = '' !== $clean ? $clean : 'Aucune sortie reçue de PHPUnit.';

        $hasWarnings = str_contains($output, 'PHP warning:') || str_contains($output, 'Warnings:');

        if ($result['success'] && !$hasWarnings) {
            return ['valid' => true, 'output' => $output];
        }
        if ($result['success']) {
            $output = "ERREUR DE VALIDATION (AVERTISSEMENT PHP DÉTECTÉ) :\n" . $output;
        }

        return ['valid' => false, 'output' => $output];
    }

    /**
     * Exécute PHPUnit avec correction statique
     *
     * @return array{valid: bool, output: string, code: string}
     */
    private function runWithPreFix(string $testCode, string $className): array
    {
        $run = $this->runPhpUnit($testCode, $className);

        for ($pass = 0; !$run['valid'] && $pass < self::MAX_PREFIX_PASSES; ++$pass) {
            $patched = $this->preFixer->fix($run['output'], $testCode);
            if (null === $patched) {
                break;
            }
            $testCode = $patched;
            $run = $this->runPhpUnit($testCode, $className);
        }

        return [...$run, 'code' => $testCode]; // ['valid' => true, 'output' => 'OK', 'code' => '<?php …']
    }

    /**
     * Exécute PHPStan
     *
     * @return array{success: bool, output: string}
     */
    private function runPhpStan(string $testCode, string $className): array
    {
        $tempFilePath = sprintf(
            '%s' . DIRECTORY_SEPARATOR . '%s_TempTestV2_%s.php',
            rtrim($this->tmpDir, '/\\'),
            $className,
            uniqid('', true)
        );

        try {
            $this->filesystem->dumpFile($tempFilePath, $testCode);

            return $this->phpStanRunner->analyze($tempFilePath);
        } finally {
            if ($this->filesystem->exists($tempFilePath)) {
                $this->filesystem->remove($tempFilePath);
            }
        }
    }

    /**
     * Exécute PHPStan avec correction statique
     *
     * @return array{success: bool, output: string, code: string, initial: string}
     */
    private function runPhpStanWithPreFix(string $testCode, string $className): array
    {
        $result = $this->runPhpStan($testCode, $className);
        $initial = $result['output']; // rapport du code tel que reçu

        for ($pass = 0; !$result['success'] && $pass < self::MAX_PREFIX_PASSES; ++$pass) {
            $patched = $this->preFixer->fix($result['output'], $testCode);
            if (null === $patched) {
                break;
            }
            $testCode = $patched;
            $result = $this->runPhpStan($testCode, $className);
        }

        return [
            ...$result, // étend le tableau ['success' => true, 'output' => '<php...'],
            'code' => $testCode, // et ajoute les clés
            'initial' => $initial
        ];
    }

    /**
     * @return array{valid: bool, output: string, phase: string}
     */
    public function check(string $testCode, string $className): array
    {
        $run = $this->runPhpUnit($testCode, $className);
        if (!$run['valid']) {
            return [...$run, 'phase' => 'PHPUnit'];
        }

        $stan = $this->runPhpStan($testCode, $className);

        return ['valid' => $stan['success'], 'output' => $stan['output'], 'phase' => 'PHPStan'];
    }

    /**
     * Mode sans LLM : exécute PHPUnit puis PHPStan avec les pré-correcteurs, comme la boucle de génération.
     *
     * @return array{valid: bool, output: string, code: string, phase: string}
     */
    public function replay(string $testCode, string $className): array
    {
        $run = $this->runWithPreFix($testCode, $className);
        if (!$run['valid']) {
            return [...$run, 'phase' => 'PHPUnit'];
        }

        $stan = $this->runPhpStanWithPreFix($run['code'], $className);
        if ($stan['success'] && $stan['code'] !== $run['code']) {
            // un correctif PHPStan a modifié le test : PHPUnit doit le revalider
            return [...$this->runWithPreFix($stan['code'], $className), 'phase' => 'PHPUnit'];
        }

        return [
            'valid' => $stan['success'],
            'output' => $stan['output'],
            'code' => $stan['code'],
            'phase' => 'PHPStan'
        ];
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
        // Certaines API (Anthropic) exigent un premier message 'user', sans 'assistant'.
        if (\count($messages) > 1) {
            $messages[] = ['role' => 'assistant', 'content' => $testCode];
        }
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
    public function  replaceDynamicHeadersInTestCode(string $testCode, string $targetNamespace, string $className): string
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