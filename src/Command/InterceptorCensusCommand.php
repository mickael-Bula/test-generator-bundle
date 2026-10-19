<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'llm:interceptors:census',
    description: 'Compte, sur les rapports connus (var/failed_tests, fixtures), ce que produit chaque intercepteur.',
)]
final class InterceptorCensusCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ErrorInterceptorRegistry $interceptors,
        private readonly TestSnapshotStore $snapshots,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'pair',
            null,
            InputOption::VALUE_REQUIRED,
            'Affiche côte à côte les indices de deux intercepteurs sur un même rapport '
            . '(ex. --pair=ExpectsOnNonMockInterceptor+StubOnRealObjectInterceptor)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // rapport => code du test voisin (les intercepteurs relisent le test par son instantané)
        $reports = [];
        foreach (glob($this->projectDir . '/var/failed_tests/*.log') ?: [] as $log) {
            $reports[$log] = (string) preg_replace('/\.log$/', '.php', $log);
        }
        $fixtures = $this->projectDir . '/tests/Fixtures/FailedTests';
        foreach (array_merge(glob($fixtures . '/*/report.log') ?: [], glob($fixtures . '/*/*/report.log') ?: []) as $log) {
            $reports[$log] = \dirname($log) . '/input.php';
        }
        if ([] === $reports) {
            $io->warning('Aucun rapport trouvé (var/failed_tests/*.log, tests/Fixtures/FailedTests/**/report.log).');

            return Command::SUCCESS;
        }

        $wanted = null !== $input->getOption('pair') ? explode('+', (string) $input->getOption('pair')) : [];
        $shown = false;
        $stats = [];
        $pairs = [];
        $pairScenarios = [];

        foreach ($reports as $log => $codeFile) {
            $report = str_replace(CaptureFixtureCommand::PLACEHOLDER, $this->projectDir, (string) file_get_contents($log));
            if (is_file($codeFile)) {
                $this->snapshots->saveForReport($report, (string) file_get_contents($codeFile));
            }
            $scenario = $this->scenarioOf($log);

            $hints = [];
            foreach ($this->interceptors->inspect($report) as $row) {
                $name = basename(str_replace('\\', '/', $row['interceptor']));
                $stats[$name] ??= ['supports' => 0, 'hints' => 0, 'silent' => 0, 'errors' => 0, 'scenarios' => []];
                $stats[$name]['supports'] += $row['supports'] ? 1 : 0;
                $stats[$name]['hints'] += null !== $row['hint'] ? 1 : 0;
                $stats[$name]['silent'] += $row['supports'] && null === $row['hint'] && null === $row['error'] ? 1 : 0;
                $stats[$name]['errors'] += null !== $row['error'] ? 1 : 0;
                if (null !== $row['hint']) {
                    $hints[$name] = $row['hint'];
                    $stats[$name]['scenarios'][$scenario] = true;
                }
            }

            $names = array_keys($hints);
            sort($names);
            foreach ($names as $i => $a) {
                foreach (\array_slice($names, $i + 1) as $b) {
                    $key = $a . ' + ' . $b;
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                    $pairScenarios[$key][$scenario] = true;
                }
            }

            if (!$shown && 2 === \count($wanted) && isset($hints[$wanted[0]], $hints[$wanted[1]])) {
                $io->section(sprintf('Rapport : %s', str_replace($this->projectDir . '/', '', $log)));
                foreach ($wanted as $name) {
                    $io->writeln(sprintf('<info>%s</info>', $name));
                    $io->writeln(OutputFormatter::escape($hints[$name]));
                    $io->newLine();
                }
                $shown = true;
            }
        }
        if (2 === \count($wanted) && !$shown) {
            $io->warning('Aucun rapport où les deux intercepteurs produisent un indice.');
        }

        uasort($stats, static fn (array $a, array $b): int => [$a['hints'], $a['supports']] <=> [$b['hints'], $b['supports']]);
        $io->title(sprintf(
            '%d rapports, %d scénarios distincts, %d intercepteurs',
            \count($reports),
            \count(array_unique(array_map($this->scenarioOf(...), array_keys($reports)))),
            \count($stats)
        ));

        $io->table(
            ['Intercepteur', 'reconnaît', 'indice', 'muet', 'erreur', 'scénarios'],
            array_map(
                static fn (string $name, array $s): array => [
                    $name, $s['supports'], $s['hints'],
                    $s['silent'] > 0 ? '<error>' . $s['silent'] . '</error>' : 0,
                    $s['errors'] > 0 ? '<error>' . $s['errors'] . '</error>' : 0,
                    \count($s['scenarios']),
                ],
                array_keys($stats),
                $stats,
            ),
        );

        $rows = [];
        foreach ($pairs as $pair => $n) {
            [$a, $b] = explode(' + ', $pair);
            $rows[] = [$pair, $n, sprintf('%.2f', $n / ($stats[$a]['hints'] + $stats[$b]['hints'] - $n)), \count($pairScenarios[$pair])];
        }
        usort($rows, static fn (array $x, array $y): int => [$y[2], $y[1]] <=> [$x[2], $x[1]]);
        if ([] !== $rows) {
            $io->section('Paires : Jaccard 1.00 = toujours ensemble (lire avec --pair avant de conclure)');
            $io->table(['Paire', 'rapports', 'Jaccard', 'scénarios communs'], \array_slice($rows, 0, 12));
        }

        return Command::SUCCESS;
    }

    /**
     * Un scénario = une suite d'étapes d'un même test, ou un échec de var/failed_tests.
     */
    private function scenarioOf(string $log): string
    {
        if (str_contains($log, '/Fixtures/FailedTests/')) {
            $parent = basename(\dirname($log));

            return 1 === preg_match('/^\d{2}$/', $parent) ? basename(\dirname($log, 2)) : $parent;
        }

        return basename($log, '.log');
    }
}