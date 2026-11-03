<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Dto\GenerationResult;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
use Mika\TestGeneratorBundle\Interceptor\Support\ProblemSignatures;
use Mika\TestGeneratorBundle\Interceptor\Support\ReportBlocks;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Llm\LlmClientInterface;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;
use Mika\TestGeneratorBundle\Quality\MethodPatcher;
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
    private const MAX_TEST_REPAIRS = 3; // tours de réparation test par test
    private const MAX_PREFIX_FAILURES = 3; // échecs consécutifs
    private const MAX_PREFIX_PASSES = 20; // Garde-fou absolu
    private ?string $baseline = null; // test existant en cours de fusion : la porte ne juge que le nouveau code.

    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly PhpUnitTestRunner $testRunner,
        private readonly PhpSyntaxValidator $syntaxValidator,
        private readonly PhpStanRunner $phpStanRunner,
        private readonly PreFixerChain $preFixer,
        private readonly TestQualityGate $qualityGate,
        private readonly TestSnapshotStore $snapshots,
        private readonly MethodPatcher $patcher,
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
     * Compatibilité (FixTestCommand) : renvoie uniquement le code, sans livraison partielle.
     *
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
        return $this->generate(
            classCode: $classCode,
            filePath: $filePath,
            fqcn: $fqcn,
            className: $className,
            model: $model,
            methodName: $methodName,
            existingTestCode: $existingTestCode,
            specContent: $specContent,
            type: $type,
            provider: $provider,
            startFromTestCode: $startFromTestCode,
            allowPartial: false, // Mettre à true pour que llm:fix:test retire silencieusement les tests non verts
        )->code;
    }

    /**
     * @param callable(string): void|null $onProgress
     *
     * @throws TestCorrectionException|\RuntimeException|\JsonException
     */
    public function generate(
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
        bool $allowPartial = true,
        ?callable $onProgress = null,
    ): GenerationResult {
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
        $repairDone = false; // un seul passage de réparation test par test
        $removed = [];
        $progress = $onProgress ?? static function (string $message): void {
        };

        while ($phpunitAttempt < self::MAX_PHPUNIT_ATTEMPTS) {
            ++$phpunitAttempt;

            $fromStart = null !== $pending;
            $testCode = $pending ?? $this->cleanLlmOutput($client->call($messages, $targetModel));
            $pending = null; // On s'assure qu'aucune fixture n'est réutilisée dans la boucle

            // Si MIKA_TEST_TRACE=1, on enregistre les sorties brutes du LLM (le code de départ n'en est pas une).
            if ($this->trace && !$fromStart) {
                $this->filesystem->dumpFile(sprintf(
                    '%s/attempts/%s_%s_attempt%d.php',
                    rtrim($this->failedTestsDir, '/\\'), date('Y-m-d_H-i-s'), $className, $phpunitAttempt,
                ), $testCode);
            }

            // Validation de la syntaxe PHP
            $syntaxResult = $this->syntaxValidator->validate($testCode);

            if (!$syntaxResult->isValid) {
                $syntaxErrorOutput = sprintf(
                    'Erreur de syntaxe à la ligne %d: %s',
                    $syntaxResult->errorLine ?? 0,
                    $syntaxResult->errorMessage ?? 'Syntaxe PHP invalide'
                );

                $errorMessage = "ERREUR DE SYNTAXE PHP :\n" . $syntaxErrorOutput . "\n\n"
                    . 'Corrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.';

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

            // Premier run invalide : réparation test par test (une seule fois)
            if (!$repairDone) {
                $repairDone = true;

                $repair = $this->repairPerTest(
                    $client,
                    $targetModel,
                    $testCode,
                    $lastOutput,
                    $classCode,
                    $filePath,
                    $fqcn,
                    $className,
                    $provider,
                    $allowPartial,
                    $progress,
                );
                $testCode = $repair['code'];
                $lastOutput = $repair['output'];

                if (!$repair['fallback']) {
                    if ($repair['valid']) {
                        $isPhpUnitValid = true;
                        $removed = $repair['removed'];
                    }
                    break;
                }
                // fallback : la boucle historique reprend avec le code et le rapport renvoyés
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
                    return $this->result($testCode, $removed, $className);
                }

                $run = $this->runWithPreFix($testCode, $className);
                $testCode = $run['code'];

                if ($run['valid']) {
                    return $this->result($testCode, $removed, $className);
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
                    && (!ProblemSignatures::isComplete($run['output'])
                        || ProblemSignatures::improves(
                            $problems, $after,
                            ProblemSignatures::assertions($run['output']),
                            ProblemSignatures::assertions($next['output']),
                        )));

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

        // Le runner a gardé l'instantané du dernier code exécuté, qui peut être un candidat rejeté : on rétablit celui du code retenu.
        $this->snapshots->save($className . 'DynamicTest.php', $testCode);

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

    /**
     * Réparation test par test : le LLM ne renvoie que la méthode en échec, remplacée par AST.
     * Les tests encore en échec après MAX_TEST_REPAIRS tours sont retirés (livraison partielle).
     *
     * @param callable(string): void $progress
     *
     * @return array{valid: bool, fallback: bool, code: string, output: string, removed: list<array{test: string, attempts: int, report: string, code: string}>}
     */
    private function repairPerTest(
        LlmClientInterface $client,
        string $model,
        string $testCode,
        string $output,
        string $classCode,
        string $filePath,
        string $fqcn,
        string $className,
        ?string $provider,
        bool $allowPartial,
        callable $progress,
    ): array {
        $builder = $this->getPromptBuilder('method_fixer');
        $attempts = [];

        for ($round = 1; $round <= self::MAX_TEST_REPAIRS; ++$round) {
            $failing = $this->failingTests($testCode, $output);
            if ([] === $failing) {
                // Plus aucun test attribuable (erreur fatale, problème de classe) : boucle historique
                return $this->repairOutcome(false, true, $testCode, $output);
            }

            $candidate = $testCode;
            foreach ($failing as $name => $report) {
                $attempts[$name] = ($attempts[$name] ?? 0) + 1;
                $progress(sprintf('Réparation de %s (tour %d/%d)', $name, $round, self::MAX_TEST_REPAIRS));

                // La classe passée au prompt est celle du dernier run : les numéros de ligne du rapport y correspondent.
                $prompts = $builder->buildPrompt(
                    classCode: $classCode,
                    filePath: $filePath,
                    fqcn: $fqcn,
                    className: $className,
                    methodName: $name,
                    existingTestCode: $testCode,
                    specContent: $report,
                    provider: $provider,
                );

                $answer = $this->cleanLlmOutput($client->call([
                    ['role' => 'system', 'content' => $prompts['system']],
                    ['role' => 'user', 'content' => $prompts['user']],
                ], $model));

                // Réponse inutilisable (méthode absente ou renommée, remplacement invalide) : l'essai est consommé
                $method = $this->patcher->fromAnswer($answer, $name);
                $patched = null === $method ? null : $this->patcher->replace($candidate, $name, $method);
                if (null !== $patched) {
                    $candidate = $patched;
                }
            }

            if ($candidate === $testCode || !$this->syntaxValidator->validate($candidate)->isValid) {
                continue; // rien de neuf à exécuter : tour suivant sans relancer PHPUnit
            }

            $run = $this->runWithPreFix($candidate, $className);
            $testCode = $run['code'];
            $output = $run['output'];

            if ($run['valid']) {
                return $this->repairOutcome(true, false, $testCode, $output);
            }
        }

        // Il reste des tests en échec après les tours
        $failing = $this->failingTests($testCode, $output);
        if ([] === $failing || !$allowPartial || $this->touchesBaseline(array_keys($failing))) {
            return $this->repairOutcome(false, true, $testCode, $output);
        }

        $removed = [];
        foreach ($failing as $name => $report) {
            $removed[] = [
                'test' => $name,
                'attempts' => $attempts[$name] ?? 0,
                'report' => $report,
                'code' => $this->patcher->extract($testCode, $name),
            ];
        }
        $stripped = $this->patcher->remove($testCode, array_keys($failing));
        $progress(sprintf('Retrait de %d test(s) irréparable(s)', count($removed)));

        $run = $this->runWithPreFix($stripped, $className);
        if (!$run['valid']) {
            // Dépendances partagées, plus aucun test… : on revient au code d'avant le retrait
            return $this->repairOutcome(false, true, $testCode, $output);
        }

        return $this->repairOutcome(true, false, $run['code'], $run['output'], $removed);
    }

    /**
     * Tests de la classe qui échouent dans ce rapport, avec leur rapport.
     *
     * @return array<string, string> nom du test => rapport
     */
    private function failingTests(string $testCode, string $output): array
    {
        $byTest = array_intersect_key(ReportBlocks::byTest($output), array_flip($this->patcher->names($testCode)));

        $reports = [];
        foreach ($byTest as $name => $blocks) {
            $reports[(string) $name] = implode("\n\n", (array) $blocks);
        }

        return $reports;
    }

    /**
     * Un test déjà présent dans le fichier existant (mode fusion) n'est jamais retiré.
     *
     * @param list<string> $names
     */
    private function touchesBaseline(array $names): bool
    {
        if (null === $this->baseline) {
            return false;
        }

        return [] !== array_intersect($names, $this->patcher->names($this->baseline));
    }

    /**
     * @param list<array{test: string, attempts: int, report: string, code: string}> $removed
     *
     * @return array{valid: bool, fallback: bool, code: string, output: string, removed: list<array{test: string, attempts: int, report: string, code: string}>}
     */
    private function repairOutcome(bool $valid, bool $fallback, string $code, string $output, array $removed = []): array
    {
        return ['valid' => $valid, 'fallback' => $fallback, 'code' => $code, 'output' => $output, 'removed' => $removed];
    }

    /**
     * @param list<array{test: string, attempts: int, report: string, code: string}> $removed
     */
    private function result(string $code, array $removed, string $className): GenerationResult
    {
        $reviewPath = [] === $removed ? null : $this->writeReviewReport($className, $removed);

        return new GenerationResult($code, $removed, $reviewPath);
    }

    /**
     * Écrit var/failed_tests/review/<date>_<Classe>.md (dossier ignoré par benchmark et compare).
     *
     * @param list<array{test: string, attempts: int, report: string, code: string}> $removed
     */
    private function writeReviewReport(string $className, array $removed): string
    {
        $lines = [
            sprintf('# Tests à revoir : %s', $className),
            '',
            '> **Ces tests ne figurent PAS dans le fichier livré.** Ils sont restés en échec après la réparation test par test et ont été retirés.',
            '',
        ];

        foreach ($removed as $item) {
            $lines[] = sprintf('## %s', $item['test']);
            $lines[] = '';
            $lines[] = sprintf('Tentatives de réparation : %d', $item['attempts']);
            $lines[] = '';
            $lines[] = 'Dernier rapport :';
            $lines[] = '';
            $lines[] = $this->fenced($item['report'], 'text');
            $lines[] = '';
            $lines[] = 'Dernière version :';
            $lines[] = '';
            $lines[] = $this->fenced($item['code'], 'php');
            $lines[] = '';
        }

        $path = sprintf(
            '%s/review/%s_%s.md',
            rtrim($this->failedTestsDir, '/\\'),
            date('Y-m-d_H-i-s'),
            $className,
        );
        $this->filesystem->dumpFile($path, implode("\n", $lines));

        return $path;
    }

    /** Bloc de code Markdown dont la clôture est plus longue que toute suite de guillemets obliques du contenu. */
    private function fenced(string $content, string $language): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $content, $matches) > 0) {
            $longest = max(array_map('strlen', $matches[0]));
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence . $language . "\n" . rtrim($content) . "\n" . $fence;
    }
}
