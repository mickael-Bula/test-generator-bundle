<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Interceptor\Support\ProblemSignatures;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
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
    name: 'llm:attempts:compare',
    description: "Pour chaque génération tracée : ce que le LLM a corrigé, laissé ou introduit d'une tentative à la suivante.",
)]
final class AttemptsCompareCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/failed_tests/attempts')] private readonly string $attemptsDir,
        private readonly TestGeneratorV2 $generator,
        private readonly CoveredClassReader $coveredClass,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::OPTIONAL, 'Nom court de la classe testée (ex. PortfolioService)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Date minimale AAAA-MM-JJ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $files = glob($this->attemptsDir . '/*_attempt*.php') ?: [];
        sort($files);
        $class = $input->getArgument('class');
        $since = $input->getOption('since');

        // Une génération = une suite de tentatives qui commence à « attempt1 ».
        $runs = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ((null !== $class && !str_contains($name, '_' . $class . '_'))
                || (null !== $since && substr($name, 0, 10) < $since)
            ) {
                continue;
            }
            if ([] === $runs || 1 === preg_match('/_attempt1\.php$/', $name)) {
                $runs[] = [];
            }
            $runs[array_key_last($runs)][] = $file;
        }

        $persistedShapes = [];
        $resolvedShapes = [];
        $totals = ['runs' => 0, 'green' => 0, 'resolved' => 0, 'persisted' => 0, 'new' => 0, 'regressed' => 0];

        foreach ($runs as $run) {
            ++$totals['runs'];
            $io->section((string) preg_replace('/_attempt\d+\.php$/', '', basename($run[0])));

            $previous = null;
            $green = false;
            $rows = [];
            $details = [];
            foreach ($run as $file) {
                $code = (string) file_get_contents($file);
                $sut = $this->coveredClass->read($code);
                if (null === $sut || !str_contains($code, 'namespace App\Tests\Dynamic;')) {
                    $rows[] = [basename($file), 'ignoré', '-', '-', '-', '-'];
                    continue;
                }

                $result = $this->generator->replay($code, basename(str_replace('\\', '/', $sut)));
                $green = $result['valid'];
                $current = $green ? [] : ProblemSignatures::of($result['output']);
                $tests = 1 === preg_match('/^(?:Tests: (\d+)|OK \((\d+) tests?)/m', $result['output'], $t) ? (int) ($t[1] ?: $t[2]) : null;
                $remaining = $green ? 'vert' : sprintf('%d/%s', \count($current), $tests ?? '?');

                if (null === $previous) {
                    $rows[] = [basename($file), $remaining, '-', '-', '-', '-'];
                } else {
                    $resolved = array_diff_key($previous, $current);
                    $persisted = array_intersect_key($previous, $current);
                    $new = array_diff_key($current, $previous);
                    $regressed = ProblemSignatures::compare($previous, $current)['regressed']; // test sain devenu rouge
                    $rows[] = [basename($file), $remaining, \count($resolved), \count($persisted), \count($new), \count($regressed)];

                    foreach (array_keys($resolved) as $s) {
                        $resolvedShapes[ProblemSignatures::shape($s)] = ($resolvedShapes[ProblemSignatures::shape($s)] ?? 0) + 1;
                        $details[] = '  <info>corrigé</info>     ' . OutputFormatter::escape($s);
                    }
                    foreach (array_keys($persisted) as $s) {
                        $persistedShapes[ProblemSignatures::shape($s)] = ($persistedShapes[ProblemSignatures::shape($s)] ?? 0) + 1;
                        $details[] = '  <comment>persistant</comment> ' . OutputFormatter::escape($s);
                    }
                    foreach (array_keys($new) as $s) {
                        $details[] = '  <fg=red>nouveau</>      ' . OutputFormatter::escape($s);
                    }
                    $totals['resolved'] += \count($resolved);
                    $totals['persisted'] += \count($persisted);
                    $totals['new'] += \count($new);
                    $totals['regressed'] += \count($regressed);
                }
                $previous = $current;
            }

            $io->table(['Tentative', 'Problèmes restants', 'Corrigés', 'Persistants', 'Nouveaux'], $rows);
            if ($output->isVerbose()) {
                $io->writeln($details);
            }
            $totals['green'] += $green ? 1 : 0;
        }

        $io->section('Bilan');
        $handled = $totals['resolved'] + $totals['persisted'];
        $io->writeln(sprintf(
            'Générations : %d, terminées au vert : %d. '
            . 'Entre tentatives : %d problèmes corrigés, %d persistants, %d introduits, '
            . '%d en régression (taux de correction : %s)',
            $totals['runs'], $totals['green'], $totals['resolved'], $totals['persisted'], $totals['new'], $totals['regressed'],
            0 === $handled ? 'n/a' : sprintf('%d %%', (int) round($totals['resolved'] / $handled * 100)),
        ));

        arsort($persistedShapes);
        arsort($resolvedShapes);
        $this->shapes($io, "Ce que le LLM ne corrige pas malgré l'indice (formes persistantes)", $persistedShapes);
        $this->shapes($io, 'Ce que le LLM corrige (formes disparues)', $resolvedShapes);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $shapes
     */
    private function shapes(SymfonyStyle $io, string $title, array $shapes): void
    {
        if ([] === $shapes) {
            return;
        }
        $io->section($title);
        $io->table(['Forme du problème', 'occurrences'], array_map(
            static fn (string $shape, int $n): array => [$shape, $n],
            \array_slice(array_keys($shapes), 0, 10),
            \array_slice($shapes, 0, 10),
        ));
    }
}