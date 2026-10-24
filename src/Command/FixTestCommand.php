<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Enum\TestType;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Resolver\TestPathResolver;
use Mika\TestGeneratorBundle\Service\TestFinalizer;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use Mika\TestGeneratorBundle\Util\TimeFormater;
use Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'llm:fix:test',
    description: 'Rejoue ou corrige un test existant (ex. var/failed_tests) avec la boucle V2, sans repartir de la spec.',
)]
class FixTestCommand extends Command
{
    private SymfonyStyle $io;
    private string $normalizedProjectDir;

    public function __construct(
        private readonly TestGeneratorV2 $testGenerator,
        private readonly TestFinalizer $finalizer,
        private readonly LlmClientFactory $llmFactory,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private readonly string $failedTestsDir,
        private readonly ClassResolver $classResolver,
        private readonly TestPathResolver $pathResolver,
        private readonly PhpSyntaxValidator $syntaxValidator,
        private readonly PreFixerChain $preFixers,
        private readonly ErrorInterceptorRegistry $interceptors,
        private readonly TestSnapshotStore $snapshots,
        private readonly CoveredClassReader $coveredClass,
        private readonly TimeFormater $timeFormater,
    ) {
        parent::__construct();

        $this->normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('test', InputArgument::REQUIRED, 'Chemin du test, ou nom de la classe testée (dernier échec de var/failed_tests)')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Classe testée (nom, chemin ou namespace). Défaut : #[CoversClass] du test')
            ->addOption('report', 'r', InputOption::VALUE_OPTIONAL, 'Analyse un rapport SANS exécuter ni appeler le LLM (sans valeur : le .log voisin du test)', false)
            ->addOption('no-llm', null, InputOption::VALUE_NONE, 'Exécute le test et les correcteurs déterministes ; aucun appel LLM')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Modèle LLM spécifique à utiliser')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Fichier de sortie (défaut : <fichier>.fixed.php)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "N'écrit aucun fichier : affiche seulement le diff");
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $startMicrotime = microtime(true);

        $this->io = new SymfonyStyle($input, $output);
        $this->io->text(sprintf('Début d\'exécution (fix V2) : <info>%s</info>', $startTime->format('H:i:s')));

        $reportOption = $input->getOption('report'); // false : absent ; null : sans valeur ; string : chemin
        $reportOnly = false !== $reportOption;

        // 1. Mêmes prérequis que la génération V2, sauf en analyse pure (rien n'est exécuté)
        $phpunitBinary = $this->projectDir . '/vendor/bin/phpunit';
        if (!$reportOnly && !file_exists($phpunitBinary) && !file_exists($phpunitBinary . '.bat')) {
            $this->io->error('PHPUnit n\'est pas installé sur le projet hôte.');

            return Command::FAILURE;
        }

        // 2. Test à traiter, et rapport voisin éventuel
        $argument = (string) $input->getArgument('test');
        [$testFile, $siblingLog] = $this->locate($argument);
        if (null === $testFile) {
            $this->io->error(sprintf('Aucun test trouvé pour "%s" (fichier inexistant, aucun échec dans %s).', $argument, $this->relative($this->failedTestsDir)));

            return Command::FAILURE;
        }
        $original = (string) file_get_contents($testFile);
        $this->io->note(sprintf('Test : %s', $this->relative($testFile)));

        $syntax = $this->syntaxValidator->validate($original);
        if (!$syntax->isValid) {
            $this->io->error(sprintf('Erreur de syntaxe ligne %d : %s. Corrigez-la avant de relancer.', $syntax->errorLine ?? 0, $syntax->errorMessage ?? ''));

            return Command::FAILURE;
        }

        // 3. Classe testée
        $sutInput = $input->getOption('class') ?? $this->coveredClass->read($original) ?? (is_file($argument) ? null : $argument);
        if (null === $sutInput) {
            $this->io->error('Classe testée introuvable : passez --class.');

            return Command::FAILURE;
        }

        try {
            $resolved = $this->classResolver->resolve($sutInput);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
        $fqcn = $resolved->className;
        $filePath = $resolved->filePath;
        $shortClassName = basename(str_replace('\\', '/', $fqcn));
        $this->io->note(sprintf('Classe ciblée (V2) : %s (%s)', $fqcn, $filePath));

        // 4. Le runner n'exécute que App\Tests\Dynamic\{Classe}DynamicTest
        [$code, $originalNamespace] = $this->toDynamic($original, $fqcn, $shortClassName);
        if (!str_contains($code, 'namespace App\Tests\Dynamic;') || !str_contains($code, "class {$shortClassName}DynamicTest")) {
            $this->io->error(sprintf('En-têtes non convertibles en App\Tests\Dynamic\%sDynamicTest : vérifiez namespace et nom de classe.', $shortClassName));

            return Command::FAILURE;
        }

        // 5. Selon le mode
        try {
            if ($reportOnly) {
                $reportPath = \is_string($reportOption) ? $reportOption : $siblingLog;
                if (null === $reportPath || !is_file($reportPath)) {
                    $this->io->error('Rapport introuvable : passez --report=<fichier>, ou placez un .log à côté du test.');

                    return Command::FAILURE;
                }
                $this->io->title(sprintf('Analyse du rapport pour : %s', $shortClassName));
                $result = $this->analyzeReport(
                    $code,
                    str_replace('%PROJECT_DIR%', $this->projectDir, (string) file_get_contents($reportPath)),
                );
                $exit = Command::SUCCESS; // simple analyse : pas de verdict
            } elseif ($input->getOption('no-llm')) {
                $this->io->title(sprintf('Relance le jeu de tests sans LLM pour : %s', $shortClassName));
                $result = $this->replay($code, $shortClassName);
                $exit = $result['green'] ? Command::SUCCESS : Command::FAILURE;
            } else {
                $model = $input->getOption('model') ?? $this->llmFactory->getDefaultModel();
                $this->io->title(sprintf('Correction V2 pour : %s', $shortClassName));
                $this->io->comment(sprintf('Reprise du test fourni, correction via la boucle V2 (%s)...', $model));

                $result = [
                    'code' => $this->testGenerator->generateForClass(
                        classCode: (string) file_get_contents($filePath),
                        filePath: $filePath,
                        fqcn: $fqcn,
                        className: $shortClassName,
                        model: $model,
                        provider: $this->llmFactory->getDefaultProvider(),
                        startFromTestCode: $code,
                    ),
                    'green' => true,
                ];
                $exit = Command::SUCCESS;
            }
        } catch (TestCorrectionException $e) {
            $this->io->error('Échec de la correction :');
            $this->io->writeln($e->getMessage());
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->io->error('Erreur lors de la correction V2 : ' . $e->getMessage());
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        }

        // 6. Un résultat vert passe par la même finalisation que la génération (use + vérification)
        if ($result['green']) {
            $final = $this->finalizer->finalize($result['code'], $shortClassName);
            if (null !== $final['reason']) {
                $this->io->warning($final['reason']);
            }
            $result['code'] = $final['code'];
        }

        $this->emit($input, $output, $testFile, $code, $result['code'], $originalNamespace, $shortClassName);
        $this->displayExecutionTime($startTime, $startMicrotime);

        return $exit;
    }

    /** @return array{code: string, green: bool} */
    private function analyzeReport(string $code, string $report): array
    {
        // Les intercepteurs relisent le test par le chemin de la trace : on enregistre l'instantané sous ce nom
        $this->snapshots->saveForReport($report, $code);

        $patched = $this->preFixers->fix($report, $code);
        $this->io->section('Correcteur déterministe');
        $this->io->writeln(null !== $patched ? 'Appliqué (une passe).' : "Aucun correcteur ne s'applique.");

        $this->showHints($report);

        return ['code' => $patched ?? $code, 'green' => false];
    }

    /** @return array{code: string, green: bool} */
    private function replay(string $code, string $shortClassName): array
    {
        $run = $this->testGenerator->replay($code, $shortClassName);

        $applied = array_map(static fn (string $f): string => basename(str_replace('\\', '/', $f)), $run['applied'] ?? []);
        $this->io->writeln('Corrections appliquées : ' . ([] === $applied ? 'aucune' : implode(', ', $applied)));
        foreach ($run['rejected'] ?? [] as [$fixer, $reason, $delta]) {
            $this->io->writeln(sprintf('<comment>Correction rejetée : %s (%s)</comment>', basename(str_replace('\\', '/', $fixer)), $reason));
            $this->io->writeln(sprintf(
                '    signatures avant : %d ; après : %d ; disparues : %d ; apparues : %d',
                $delta['before'], $delta['after'], \count($delta['disappeared']), \count($delta['appeared']),
            ));
            foreach (\array_slice($delta['regressed'], 0, 5) as $signature) {
                $this->io->writeln('    régression : ' . OutputFormatter::escape($signature));
            }
        }

        $this->io->section(sprintf('%s : %s', $run['phase'], $run['valid'] ? 'vert' : 'rouge'));
        if (!$run['valid']) {
            $this->io->writeln(OutputFormatter::escape(trim($run['output'])));
            $this->showHints($run['output']);
        }

        return ['code' => $run['code'], 'green' => $run['valid']];
    }

    private function showHints(string $report): void
    {
        $hints = $this->interceptors->resolveFromErrorOutput($report);

        $this->io->section('Indices transmis au LLM (intercepteurs)');
        $this->io->writeln(null !== $hints && '' !== trim($hints)
            ? OutputFormatter::escape($hints)
            : "Aucun intercepteur ne s'applique.");
    }

    private function emit(
        InputInterface $input,
        OutputInterface $output,
        string $testFile,
        string $before,
        string $after,
        ?string $originalNamespace,
        string $shortClassName,
    ): void {
        $this->io->section('Diff (avant → après)');
        $diff = $this->diff($before, $after);
        $output->writeln('' !== $diff ? $diff : '(aucune modification)', OutputInterface::OUTPUT_RAW);

        if ($input->getOption('dry-run') || $after === $before) {
            return;
        }

        // Un test qui n'était pas au format Dynamic retrouve son namespace et son nom d'origine
        $code = null !== $originalNamespace
            ? $this->testGenerator->replaceDynamicHeadersInTestCode($after, $originalNamespace, $shortClassName)
            : $after;

        $target = $input->getOption('output') ?? preg_replace('/\.php$/', '.fixed.php', $testFile);
        file_put_contents($target, $code);
        $this->io->success(sprintf('Résultat écrit dans : %s', $this->relative($target)));
    }

    /** @return array{0: ?string, 1: ?string} [test, rapport .log voisin] */
    private function locate(string $argument): array
    {
        if (is_file($argument)) {
            $log = preg_replace('/\.php$/', '.log', $argument);

            return [$argument, is_file($log) ? $log : null];
        }

        // Nom de classe : dernier échec écrit par TestGeneratorV2::saveFailedTest()
        $short = basename(str_replace('\\', '/', ltrim($argument, '\\')));
        $matches = glob(rtrim($this->failedTestsDir, '/\\') . '/*_' . $short . '_FailedTestV2.php') ?: [];
        if ([] === $matches) {
            return [null, null];
        }
        sort($matches); // préfixe Y-m-d_H-i-s : l'ordre alphabétique est chronologique
        $file = (string) end($matches);
        $log = preg_replace('/\.php$/', '.log', $file);

        return [$file, is_file($log) ? $log : null];
    }

    /**
     * @return array{0: string, 1: ?string} [code au format Dynamic, namespace d'origine à restaurer, ou null]
     * @throws \ReflectionException
     */
    private function toDynamic(string $code, string $fqcn, string $short): array
    {
        if (str_contains($code, 'namespace App\Tests\Dynamic;') && str_contains($code, "class {$short}DynamicTest")) {
            return [$code, null];
        }
        $namespace = $this->pathResolver->resolve($fqcn, $short, TestType::UNIT)->targetNamespace;

        return [$this->testGenerator->replaceDynamicHeadersInExistingTestCode($code, $namespace, $short), $namespace];
    }

    private function diff(string $before, string $after): string
    {
        return (new Differ(new UnifiedDiffOutputBuilder("--- avant\n+++ après\n")))->diff($before, $after);
    }

    private function relative(string $path): string
    {
        return str_replace($this->normalizedProjectDir . '/', '', str_replace('\\', '/', $path));
    }

    /**
     * @throws \Exception
     */
    private function displayExecutionTime(\DateTimeImmutable $startTime, float $startMicrotime): void
    {
        [$formatedStartTime,, $formattedDuration] = $this->timeFormater->displayExecutionTime($startTime, $startMicrotime);

        $this->io->newLine();
        $this->io->definitionList(
            $formatedStartTime,
            $formattedDuration,
        );
    }
}