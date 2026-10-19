<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
use Mika\TestGeneratorBundle\Quality\TestQualityGate;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'llm:fixture:capture',
    description: "Capture un cas de non-régression : une passe, ou toute la boucle déterministe avec --chain.",
)]
final class CaptureFixtureCommand extends Command
{
    public const PLACEHOLDER = '%PROJECT_DIR%';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.project_dir%/tests/Fixtures/FailedTests')] private readonly string $fixturesDir,
        private readonly PreFixerChain $preFixers,
        private readonly ErrorInterceptorRegistry $interceptors,
        private readonly TestSnapshotStore $snapshots,
        private readonly TestGeneratorV2 $generator,
        private readonly CoveredClassReader $coveredClass,
        private readonly TestQualityGate $qualityGate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nom du scénario (kebab-case), ex. ma-classe--run-1')
            ->addArgument('test', InputArgument::REQUIRED, 'Test en échec (ex. var/failed_tests/…_FailedTest.php)')
            ->addOption('report', 'r', InputOption::VALUE_REQUIRED, 'Mode une passe : rapport à utiliser à la place du .log voisin')
            ->addOption('chain', null, InputOption::VALUE_NONE, 'Exécute PHPUnit/PHPStan à chaque passe et capture chaque étape (01, 02, …)')
            ->addOption('max-steps', null, InputOption::VALUE_REQUIRED, "Nombre maximal d'étapes en mode --chain", '6')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recrée le scénario s\'il existe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = (string) $input->getArgument('name');
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            $io->error('Nom invalide : minuscules, chiffres et tirets uniquement.');

            return Command::FAILURE;
        }

        $testFile = (string) $input->getArgument('test');
        if (!is_file($testFile)) {
            $io->error(sprintf('Fichier introuvable : %s', $testFile));

            return Command::FAILURE;
        }
        $code = (string) file_get_contents($testFile);
        if (!str_contains($code, 'namespace App\Tests\Dynamic;')) {
            $io->error('Le test doit être au format du runner (App\Tests\Dynamic), comme les fichiers de var/failed_tests.');

            return Command::FAILURE;
        }

        $chain = (bool) $input->getOption('chain');
        $reportFile = $input->getOption('report') ?? preg_replace('/\.php$/', '.log', $testFile);
        if (!$chain && !is_file($reportFile)) {
            $io->error(sprintf('Rapport introuvable : %s (passez --report, ou utilisez --chain).', $reportFile));

            return Command::FAILURE;
        }

        $base = $this->fixturesDir . '/' . $name;
        $kept = [];
        if (is_dir($base)) {
            if (!$input->getOption('force')) {
                $io->error(sprintf('Le scénario "%s" existe déjà (--force pour le recréer).', $name));

                return Command::FAILURE;
            }
            foreach (Finder::create()->files()->in($base)->name('forbidden.txt') as $file) {
                $kept[$file->getRelativePathname()] = $file->getContents(); // ex. « 05/forbidden.txt »
            }
            (new Filesystem())->remove($base);
        }

        if ($chain) {
            $exit = $this->captureChain($io, $output, $base, $code, max(1, (int) $input->getOption('max-steps')));
        } else {
            $this->captureStep($io, $output, $base, $code, (string) file_get_contents($reportFile));
            $io->success(sprintf('Cas "%s" créé.', $name));
            $exit = Command::SUCCESS;
        }

        $this->restore($io, $base, $kept);

        return $exit;
    }

    private function captureChain(SymfonyStyle $io, OutputInterface $output, string $base, string $code, int $maxSteps): int
    {
        $sut = $this->coveredClass->read($code);
        if (null === $sut) {
            $io->error('Classe testée introuvable (#[CoversClass] absent) : nécessaire pour exécuter le test.');

            return Command::FAILURE;
        }
        $short = basename(str_replace('\\', '/', $sut));

        if (1 !== preg_match('/\bclass\s+' . preg_quote($short, '/') . 'DynamicTest\b/', $code)) {
            $io->error(sprintf('Le runner exécute tests/Dynamic/%1$sDynamicTest.php : la classe du test doit s\'appeler %1$sDynamicTest.', $short));

            return Command::FAILURE;
        }

        $io->writeln('Correcteurs, par ordre de passage : ' . implode(', ', array_map($this->short(...), $this->preFixers->names())));

        $original = $code;
        $applied = [];
        $stop = sprintf('limite de %d étapes atteinte (--max-steps)', $maxSteps);

        for ($step = 1; $step <= $maxSteps; ++$step) {
            $check = $this->generator->check($code, $short);
            if ($check['valid']) {
                $stop = 1 === $step ? 'le test est déjà vert' : 'vert (PHPUnit, PHPStan et qualité), résolu sans LLM';
                break;
            }

            $io->section(sprintf('Étape %02d : %s rouge', $step, $check['phase']));
            $result = $this->captureStep($io, $output, sprintf('%s/%02d', $base, $step), $code, $check['output']);

            if (null === $result) {
                $stop = sprintf('étape %02d : plus aucun correcteur déterministe, le reste revient au LLM', $step);
                break;
            }
            $applied[$step] = $result['fixer'];
            $code = $result['code'];
        }

        $io->section('Bilan du scénario');
        $io->table(
            ['Étape', 'Correcteur appliqué'],
            array_map(fn (int $s, string $f): array => [sprintf('%02d', $s), $this->short($f)], array_keys($applied), $applied),
        );
        $io->writeln('Arrêt : ' . $stop);

        if ($code !== $original) {
            $fs = new Filesystem();
            $fs->dumpFile($base . '/final.php', $code);
            $fs->dumpFile($base . '/final.diff', $this->diff($original, $code, 'original', 'final'));
            $io->writeln(sprintf('Diff cumulé : %s/final.diff', $base));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{code: string, fixer: string}|null  le code après une passe de la chaîne, ou null si aucun correcteur ne s'applique
     */
    private function captureStep(SymfonyStyle $io, OutputInterface $output, string $dir, string $code, string $rawReport): ?array
    {
        $report = str_replace(self::PLACEHOLDER, $this->projectDir, $rawReport);

        // Rapport fidèle au pipeline : TestGeneratorV2 ajoute la section « qualité » à la sortie PHPUnit
        $quality = $this->qualityGate->report($code);
        if (null !== $quality && !str_contains($report, 'ERREUR DE QUALITÉ')) {
            $report .= "\n\n" . $quality;
        }

        $fs = new Filesystem();
        $fs->dumpFile($dir . '/input.php', $code);
        $fs->dumpFile($dir . '/report.log', $this->normalize($report));
        $this->snapshots->saveForReport($report, $code);

        $step = $this->preFixers->apply($report, $code);
        if (null !== $step) {
            $fs->dumpFile($dir . '/expected.php', $step['code']);
        }

        $rows = $this->interceptors->inspect($report);
        $firstLines = [];
        foreach ($rows as $row) {
            if (null !== $row['hint']) {
                $firstLines[] = (string) strtok($row['hint'], "\n");
            }
        }
        if ([] !== $firstLines) {
            $fs->dumpFile($dir . '/hints.txt', implode("\n", $firstLines) . "\n");
        }

        $sut = $this->coveredClass->read($code);
        $meta = ['captured' => date('Y-m-d'), 'fixer' => $step['fixer'] ?? null];
        if (null !== $sut && class_exists($sut)) {
            $meta += ['sut' => $sut, 'sut_sha1' => sha1_file((string) (new \ReflectionClass($sut))->getFileName())];
        }
        $fs->dumpFile($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if (null === $step) {
            $io->writeln("Correcteur déterministe : aucun ne s'applique (la fixture exigera que la chaîne renvoie null).");
        } else {
            $io->writeln(sprintf('Correcteur appliqué : <info>%s</info>. Diff input.php → expected.php :', $this->short($step['fixer'])));
            $output->writeln($this->diff($code, $step['code']), OutputInterface::OUTPUT_RAW);
        }

        $io->writeln(sprintf('Intercepteurs enregistrés : %d', \count($rows)));
        foreach ($rows as $row) {
            $label = match (true) {
                null !== $row['error'] => 'ERREUR ' . $row['error'],
                null !== $row['hint'] => 'indice : ' . strtok($row['hint'], "\n"),
                $row['supports'] => "reconnaît l'erreur mais ne produit aucun indice",
                default => null,
            };
            if (null === $label) {
                continue;
            }
            $io->writeln(sprintf('  - %s : %s', $this->short($row['interceptor']), OutputFormatter::escape($label)));
            if ($output->isVerbose() && null !== $row['hint']) { // -v : texte complet envoyé au LLM
                $io->writeln(OutputFormatter::escape((string) preg_replace('/^/m', '      | ', $row['hint'])));
            }
        }

        if (null === $step && [] === $firstLines) {
            $io->writeln('Aucun correcteur ni indice. Début du rapport :');
            $io->writeln(OutputFormatter::escape(implode("\n", \array_slice(explode("\n", trim($report)), 0, 12))));
        }

        return $step;
    }

    private function short(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }

    private function normalize(string $report): string
    {
        $clean = preg_replace('/\x1b\[[0-9;]*[mK]/', '', $report) ?? $report;
        foreach (array_unique([$this->projectDir, (string) realpath($this->projectDir)]) as $path) {
            $clean = str_replace($path, self::PLACEHOLDER, $clean);
        }

        // La durée varie à chaque exécution : elle ne sert qu'à brouiller les diffs
        return (string) preg_replace('/^Time: .*\R?/m', '', $clean);
    }

    private function diff(string $before, string $after, string $from = 'input.php', string $to = 'expected.php'): string
    {
        return (new Differ(new UnifiedDiffOutputBuilder(sprintf("--- %s\n+++ %s\n", $from, $to))))->diff($before, $after);
    }

    /**
     * @param array<string, string> $kept
     */
    private function restore(SymfonyStyle $io, string $base, array $kept): void
    {
        foreach ($kept as $relative => $content) {
            if (is_dir(\dirname($base . '/' . $relative))) {
                (new Filesystem())->dumpFile($base . '/' . $relative, $content);
            } else {
                $io->warning(sprintf('%s non restauré : cette étape n\'existe plus dans la nouvelle capture.', $relative));
            }
        }
    }
}