<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nom du scénario (kebab-case), ex. position-manager--run-1')
            ->addArgument('test', InputArgument::REQUIRED, 'Test en échec (ex. var/failed_tests/…_FailedTestV2.php)')
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
        if (is_dir($base)) {
            if (!$input->getOption('force')) {
                $io->error(sprintf('Le scénario "%s" existe déjà (--force pour le recréer).', $name));

                return Command::FAILURE;
            }
            (new Filesystem())->remove($base); // un scénario en étapes doit être recréé en entier
        }

        if ($chain) {
            return $this->captureChain($io, $output, $base, $code, max(1, (int) $input->getOption('max-steps')));
        }

        $this->captureStep($io, $output, $base, $code, (string) file_get_contents($reportFile));
        $io->success(sprintf('Cas "%s" créé.', $name));

        return Command::SUCCESS;
    }

    private function captureChain(SymfonyStyle $io, OutputInterface $output, string $base, string $code, int $maxSteps): int
    {
        $sut = $this->coveredClass->read($code);
        if (null === $sut) {
            $io->error('Classe testée introuvable (#[CoversClass] absent) : nécessaire pour exécuter le test.');

            return Command::FAILURE;
        }
        $short = basename(str_replace('\\', '/', $sut));

        for ($step = 1; $step <= $maxSteps; ++$step) {
            $check = $this->generator->check($code, $short);

            if ($check['valid']) {
                $io->success(1 === $step
                    ? 'Le test est déjà vert : rien à capturer.'
                    : sprintf('Étape %02d : vert (PHPUnit et PHPStan). Résolu sans LLM en %d correction(s).', $step, $step - 1));

                return Command::SUCCESS;
            }

            $io->section(sprintf('Étape %02d : %s rouge', $step, $check['phase']));
            $patched = $this->captureStep($io, $output, sprintf('%s/%02d', $base, $step), $code, $check['output']);

            if (null === $patched) {
                $io->note(sprintf("Étape %02d : plus aucun correcteur déterministe. Le reste revient au LLM : c'est le prochain problème à traiter.", $step));

                return Command::SUCCESS;
            }
            $code = $patched;
        }

        $io->warning(sprintf('Limite de %d étapes atteinte (--max-steps) : scénario non terminé.', $maxSteps));

        return Command::SUCCESS;
    }

    /** @return string|null le code après une passe de la chaîne, ou null si aucun correcteur ne s'applique */
    private function captureStep(SymfonyStyle $io, OutputInterface $output, string $dir, string $code, string $rawReport): ?string
    {
        $report = str_replace(self::PLACEHOLDER, $this->projectDir, $rawReport);
        $fs = new Filesystem();
        $fs->dumpFile($dir . '/input.php', $code);
        $fs->dumpFile($dir . '/report.log', $this->normalize($report));

        // Les intercepteurs relisent le test par le chemin de la trace : on enregistre l'instantané sous ce nom
        if (1 === preg_match('#(/\S+Test\.php):\d+#', $report, $m)) {
            $this->snapshots->save($m[1], $code);
        }

        $patched = $this->preFixers->fix($report, $code);
        if (null !== $patched) {
            $fs->dumpFile($dir . '/expected.php', $patched);
        }

        // Brouillon de hints.txt : affiche la première ligne de chaque indice produit
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
        $meta = ['captured' => (new \DateTimeImmutable())->format('Y-m-d')];
        if (null !== $sut && class_exists($sut)) {
            $meta += ['sut' => $sut, 'sut_sha1' => sha1_file((string) (new \ReflectionClass($sut))->getFileName())];
        }
        $fs->dumpFile($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // Affichage des indices
        $io->writeln(sprintf('Intercepteurs enregistrés : %d', \count($rows)));
        foreach ($rows as $row) {
            $label = match (true) {
                null !== $row['error'] => 'ERREUR ' . $row['error'],
                null !== $row['hint'] => 'indice : ' . strtok($row['hint'], "\n"),
                $row['supports'] => "reconnaît l'erreur mais ne produit aucun indice",
                default => null, // ne s'applique pas à ce rapport : on n'encombre pas la sortie
            };
            if (null !== $label) {
                $io->writeln(sprintf(
                    '  - %s : %s',
                    basename(str_replace('\\', '/', $row['interceptor'])),
                    OutputFormatter::escape($label)
                ));
            }
        }
        if ([] === $firstLines) {
            $io->writeln('Indices LLM : aucun.');
        }

        return $patched;
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

    private function diff(string $before, string $after): string
    {
        return (new Differ(new UnifiedDiffOutputBuilder("--- input.php\n+++ expected.php\n")))->diff($before, $after);
    }
}