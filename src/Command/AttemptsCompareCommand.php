<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Interceptor\Support\ProblemSignatures;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
        $totals = ['runs' => 0, 'green' => 0, 'resolved' => 0, 'persisted' => 0, 'new' => 0];

        foreach ($runs as $run) {
            ++$totals['runs'];
            $io->section((string) preg_replace('/_attempt\d+\.php$/', '', basename($run[0])));

            $previous = null;
            $green = false;
            $rows = [];
            foreach ($run as $file) {
                $code = (string) file_get_contents($file);
                $sut = $this->coveredClass->read($code);
                if (null === $sut || !str_contains($code, 'namespace App\Tests\Dynamic;')) {
                    $rows[] = [basename($file), 'ignoré', '-', '-', '-'];
                    continue;
                }

                $result = $this->generator->replay($code, basename(str_replace('\\', '/', $sut)));
                $green = $result['valid'];
                $current = $green ? [] : ProblemSignatures::of($result['output']);

                if (null === $previous) {
                    $rows[] = [basename($file), $green ? 'vert' : \count($current), '-', '-', '-'];
                } else {
                    $resolved = array_diff_key($previous, $current);
                    $persisted = array_intersect_key($previous, $current);
                    $new = array_diff_key($current, $previous);
                    $rows[] = [basename($file), $green ? 'vert' : \count($current), \count($resolved), \count($persisted), \count($new)];

                    foreach (array_keys($resolved) as $s) {
                        $resolvedShapes[ProblemSignatures::shape($s)] = ($resolvedShapes[ProblemSignatures::shape($s)] ?? 0) + 1;
                    }
                    foreach (array_keys($persisted) as $s) {
                        $persistedShapes[ProblemSignatures::shape($s)] = ($persistedShapes[ProblemSignatures::shape($s)] ?? 0) + 1;
                    }
                    $totals['resolved'] += \count($resolved);
                    $totals['persisted'] += \count($persisted);
                    $totals['new'] += \count($new);
                }
                $previous = $current;
            }

            $io->table(['Tentative', 'Problèmes restants', 'Corrigés', 'Persistants', 'Nouveaux'], $rows);
            $totals['green'] += $green ? 1 : 0;
        }

        $io->section('Bilan');
        $handled = $totals['resolved'] + $totals['persisted'];
        $io->writeln(sprintf(
            'Générations : %d, terminées au vert : %d. Entre tentatives : %d problèmes corrigés, %d persistants, %d introduits (taux de correction : %s).',
            $totals['runs'], $totals['green'], $totals['resolved'], $totals['persisted'], $totals['new'],
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