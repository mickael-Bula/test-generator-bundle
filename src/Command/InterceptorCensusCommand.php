<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

        $stats = [];
        $pairs = [];
        foreach ($reports as $log => $codeFile) {
            $report = str_replace(CaptureFixtureCommand::PLACEHOLDER, $this->projectDir, (string) file_get_contents($log));
            if (is_file($codeFile)) {
                $this->snapshots->saveForReport($report, (string) file_get_contents($codeFile));
            }

            $fired = [];
            foreach ($this->interceptors->inspect($report) as $row) {
                $name = basename(str_replace('\\', '/', $row['interceptor']));
                $stats[$name] ??= ['supports' => 0, 'hints' => 0, 'silent' => 0, 'errors' => 0];
                $stats[$name]['supports'] += $row['supports'] ? 1 : 0;
                $stats[$name]['hints'] += null !== $row['hint'] ? 1 : 0;
                $stats[$name]['silent'] += $row['supports'] && null === $row['hint'] && null === $row['error'] ? 1 : 0;
                $stats[$name]['errors'] += null !== $row['error'] ? 1 : 0;
                if (null !== $row['hint']) {
                    $fired[] = $name;
                }
            }

            sort($fired);
            foreach ($fired as $i => $a) {
                foreach (\array_slice($fired, $i + 1) as $b) {
                    $pairs[$a . ' + ' . $b] = ($pairs[$a . ' + ' . $b] ?? 0) + 1;
                }
            }
        }

        uasort($stats, static fn (array $a, array $b): int => [$a['hints'], $a['supports']] <=> [$b['hints'], $b['supports']]);
        $io->title(sprintf('%d rapports analysés, %d intercepteurs', \count($reports), \count($stats)));
        $io->table(
            ['Intercepteur', 'reconnaît', 'indice', 'muet', 'erreur'],
            array_map(
                static fn (string $name, array $s): array => [
                    $name, $s['supports'], $s['hints'],
                    $s['silent'] > 0 ? '<error>' . $s['silent'] . '</error>' : 0,
                    $s['errors'] > 0 ? '<error>' . $s['errors'] . '</error>' : 0,
                ],
                array_keys($stats),
                $stats,
            ),
        );

        arsort($pairs);
        if ([] !== $pairs) {
            $io->section('Intercepteurs qui produisent un indice sur les mêmes rapports (risque de doublon ou de contradiction)');
            $io->table(['Paire', 'rapports'], array_map(
                static fn (string $pair, int $n): array => [$pair, $n],
                \array_slice(array_keys($pairs), 0, 10),
                \array_slice($pairs, 0, 10),
            ));
        }

        return Command::SUCCESS;
    }
}