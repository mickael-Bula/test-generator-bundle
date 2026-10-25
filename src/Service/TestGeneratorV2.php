<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
use Mika\TestGeneratorBundle\Interceptor\Support\ProblemSignatures;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;
use Mika\TestGeneratorBundle\Quality\TestQualityGate;
use Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Filesystem\Filesystem;

final class TestGeneratorV2
{
    private const MAX_PHPUNIT_ATTEMPTS = 3;
    private const MAX_PHPSTAN_ATTEMPTS = 2;
    private const MAX_PREFIX_FAILURES = 3;   // échecs consécutifs
    private const MAX_PREFIX_PASSES = 20; // Garde-fou absolu
    private ?string $baseline = null; // test existant en cours de fusion : la porte ne juge que le nouveau code.

    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly PhpUnitTestRunner $testRunner,
        private readonly PhpSyntaxValidator $syntaxValidator,
        private readonly PhpStanRunner $phpStanRunner,
        private readonly PreFixerChain $preFixer,
        private readonly TestQualityGate $qualityGate,
        #[AutowireIterator('mika_test_generator.prompt_builder')] private readonly iterable $promptBuilders, // Récupère toutes les classes portant ce tag
        #[Autowire('%kernel.project_dir%/var/tmp')] private readonly string $tmpDir = 'var/tmp',
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private readonly string $failedTestsDir = 'var/failed_tests',
        #[Autowire('%env(bool:default::MIKA_TEST_TRACE)%')] private readonly bool $trace = false,
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
        $this->baseline = $existingTestCode;
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

            // Si MIKA_TEST_TRACE=1, on enregistre les sorties brutes de chaque tentative (utilisées ensuite comme fixtures).
            if ($this->trace) {
                $this->filesystem->dumpFile(sprintf(
                    '%s/attempts/%s_%s_attempt%d.php',
                    rtrim($this->failedTestsDir, '/\\'), date('Y-m-d_H-i-s'), $className, $phpunitAttempt,
                ), $testCode);
            }

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
        $result = $this->testRunner->runTest($testCode, $className);

        $clean = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $result['output'] ?? '') ?? '';
        $clean = str_replace(["\r\n", "\r"], "\n", $clean);
        $output = '' !== $clean ? $clean : 'Aucune sortie reçue de PHPUnit.';

        $hasWarnings = str_contains($output, 'PHP warning:') || str_contains($output, 'Warnings:');
        $quality = $this->qualityGate->report($testCode, $this->baseline);

        if ($result['success'] && $hasWarnings) {
            $output = "ERREUR DE VALIDATION (AVERTISSEMENT PHP DÉTECTÉ) :\n" . $output;
        }
        if (null !== $quality) {
            $output .= "\n\n" . $quality;
        }

        return ['valid' => $result['success'] && !$hasWarnings && null === $quality, 'output' => $output];
    }

    /**
     * Exécute PHPUnit avec correction statique
     *
     * @return array{valid: bool, output: string, code: string, applied: list<string>}
     */
    private function runWithPreFix(string $testCode, string $className): array
    {
        $run = $this->runPhpUnit($testCode, $className);
        $problems = ProblemSignatures::of($run['output']);
        $seen = [sha1($testCode) => true];
        $skip = [];
        $applied = [];
        $failures = 0;
        $rejected = [];

        for ($pass = 0; !$run['valid'] && $pass < self::MAX_PREFIX_PASSES && $failures < self::MAX_PREFIX_FAILURES; ++$pass) {
            $step = $this->preFixer->apply($run['output'], $testCode, $skip);
            if (null === $step || isset($seen[sha1($step['code'])])) {
                break; // plus aucun correcteur applicable, ou retour à une version déjà vue (cycle)
            }
            $seen[sha1($step['code'])] = true;

            $next = $this->runPhpUnit($step['code'], $className);
            $after = ProblemSignatures::of($next['output']);

            $progress = $next['valid']
                || (ProblemSignatures::isComplete($next['output'])
                    && (!ProblemSignatures::isComplete($run['output']) || ProblemSignatures::improves($problems, $after)));

            if ($progress) {
                $testCode = $step['code'];
                $run = $next;
                $problems = $after;
                $skip = [];          // le rapport a changé : tous les correcteurs redeviennent candidats
                $failures = 0;
                $applied[] = $step['fixer'];
                continue;
            }

            $delta = [
                ...ProblemSignatures::compare($problems, $after),
                'before' => \count($problems),
                'after' => \count($after),
                'signatures' => array_keys($problems),
                'patch' => $this->unifiedDiff($testCode, $step['code']),
            ];

            // Aucun problème résolu, ou régression : on garde le code précédent et on écarte ce correcteur (on affiche la raison du rejet).
            $reason = match (true) {
                !ProblemSignatures::isComplete($next['output']) => 'rapport incomplet après la correction (erreur fatale ou de syntaxe)',
                [] !== $delta['regressed'] => 'régression : un test sain est devenu rouge',
                default => 'aucun problème résolu',
            };
            $rejected[] = [$step['fixer'], $reason, $delta];
            $skip[] = $step['fixer'];
            ++$failures;
        }

        // ['valid' => true, 'output' => 'OK', 'code' => '<?php …', 'applied' => '...']
        return [...$run, 'code' => $testCode, 'applied' => $applied, 'rejected' => $rejected];
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
        $applied = [];

        for ($pass = 0; !$result['success'] && $pass < self::MAX_PREFIX_PASSES; ++$pass) {
            $step = $this->preFixer->apply($result['output'], $testCode);
            if (null === $step) {
                break;
            }
            $testCode = $step['code'];
            $applied[] = $step['fixer'];
            $result = $this->runPhpStan($testCode, $className);
        }

        return [
            ...$result, // étend le tableau ['success' => true, 'output' => '<php...'],
            'code' => $testCode, // et ajoute les clés
            'initial' => $initial,
            'applied' => $applied,
        ];
    }

    /**
     * @return array{valid: bool, output: string, phase: string}
     */
    public function check(string $testCode, string $className): array
    {
        // On s'assure qu'aucune valeur laissée par une géneration précédente ne fausse le contrôle.
        $this->baseline = null;

        $run = $this->runPhpUnit($testCode, $className);
        if (!$run['valid']) {
            $phpunitGreen = 1 === preg_match('/^OK\b/m', $run['output']) && str_contains($run['output'], 'ERREUR DE QUALITÉ');

            return [...$run, 'phase' => $phpunitGreen ? 'Qualité' : 'PHPUnit'];
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
        // On s'assure qu'aucune valeur laissée par une géneration précédente ne fausse le contrôle.
        $this->baseline = null;

        $run = $this->runWithPreFix($testCode, $className);
        if (!$run['valid']) {
            return [...$run, 'phase' => 'PHPUnit'];
        }

        $stan = $this->runPhpStanWithPreFix($run['code'], $className);
        if ($stan['success'] && $stan['code'] !== $run['code']) {
            // un correctif PHPStan a modifié le test : PHPUnit doit le revalider
            $again = $this->runWithPreFix($stan['code'], $className);

            return [
                ...$again, // On place $again en premier pour que ses clés 'applied'/'rejected' soient écrasées
                'applied' => [...$run['applied'], ...$stan['applied'], ...$again['applied']],
                'rejected' => [...$run['rejected'], ...$again['rejected']],
                'phase' => 'PHPUnit',
            ];
        }

        return [
            'valid' => $stan['success'],
            'output' => $stan['output'],
            'code' => $stan['code'],
            'phase' => 'PHPStan',
            'applied' => [...$run['applied'], ...$stan['applied']],
            'rejected' => $run['rejected'],
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

    private function unifiedDiff(string $before, string $after): string
    {
        return (new Differ(new UnifiedDiffOutputBuilder("--- avant\n+++ après\n")))->diff($before, $after);
    }
}