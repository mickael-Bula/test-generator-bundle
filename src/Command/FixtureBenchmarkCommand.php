<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Interceptor\Support\ProblemSignatures;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
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
    name: 'llm:fixture:benchmark',
    description: 'Rejoue la chaîne déterministe sur tous les tests sauvegardés et classe les problèmes restants.',
)]
final class FixtureBenchmarkCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/failed_tests')] private readonly string $failedDir,
        private readonly TestGeneratorV2 $generator,
        private readonly CoveredClassReader $coveredClass,
        private readonly ErrorInterceptorRegistry $interceptors,
        private readonly TestSnapshotStore $snapshots,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Nombre maximal de fichiers rejoués',
            '50'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $files = array_merge(glob($this->failedDir . '/*_FailedTestV2.php') ?: [], glob($this->failedDir . '/attempts/*.php') ?: []);
        $files = \array_slice($files, 0, max(1, (int) $input->getOption('limit')));

        $rows = [];
        $remaining = []; // forme du problème => [occurrences, fichiers]
        $counts = ['déjà vert' => 0, 'résolu sans LLM' => 0, 'non résolu' => 0];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $sut = $this->coveredClass->read($code);
            if (null === $sut || !str_contains($code, 'namespace App\Tests\Dynamic;')) {
                // On indique quelle part du corpus n'est pas rejoué.
                $io->writeln(sprintf('<comment>ignoré : %s</comment>', basename($file)));
                continue;
            }

            $result = $this->generator->replay($code, basename(str_replace('\\', '/', $sut)));
            $applied = \count($result['applied'] ?? []);
            $verdict = $result['valid'] ? (0 === $applied ? 'déjà vert' : 'résolu sans LLM') : 'non résolu';
            ++$counts[$verdict];
            $rows[] = [basename($file), $verdict, $result['phase'] ?? '-', $applied, \count($result['rejected'] ?? [])];

            if (!$result['valid']) {
                $this->snapshots->saveForReport($result['output'], $result['code']);
                $blocks = $this->blocks($result['output']);
                if ([] === $blocks) {
                    $io->writeln(sprintf('<comment>%s : aucun problème reconnu dans la sortie. Début :</comment>', basename($file)));
                    $io->writeln(OutputFormatter::escape(implode("\n", \array_slice(explode("\n", trim($result['output'])), 0, 6))));
                }
                foreach ($blocks as $signature => $block) {
                    $shape = $this->shape($signature);
                    $remaining[$shape]['n'] = ($remaining[$shape]['n'] ?? 0) + 1;
                    $remaining[$shape]['files'][basename($file)] = true;

                    // Un problème est « couvert » si au moins un intercepteur produit un indice sur CE bloc isolé
                    $covered = [] !== array_filter($this->interceptors->inspect($block), static fn (array $r): bool => null !== $r['hint']);
                    $remaining[$shape]['covered'] = ($remaining[$shape]['covered'] ?? true) && $covered;
                }
            }
        }

        $io->section('Résultat par fichier');
        $io->table(['Fichier', 'Résultat', 'Phase', 'Corrigés', 'Rejetés'], $rows);

        $total = array_sum($counts);
        $io->writeln(sprintf(
            'Déjà verts : %d. Résolus sans LLM : %d. Non résolus : %d (sur %d).',
            $counts['déjà vert'], $counts['résolu sans LLM'], $counts['non résolu'], $total,
        ));

        uasort($remaining, static fn (array $a, array $b): int => [\count($b['files']), $b['n']] <=> [\count($a['files']), $a['n']]);
        if ([] !== $remaining) {
            $io->section('Problèmes restants, du plus répandu au moins répandu (candidats pour le prochain correcteur)');
            $io->table(['Forme du problème', 'occurrences', 'fichiers', 'indice LLM'], array_map(
                static fn (string $shape, array $r): array => [$shape, $r['n'], \count($r['files']), $r['covered'] ? 'oui' : '<error>aucun</error>'],
                \array_slice(array_keys($remaining), 0, 15),
                \array_slice($remaining, 0, 15),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Message sans ce qui varie : noms de classes, chaînes, nombres.
     */
    private function shape(string $signature): string
    {
        $message = substr((string) strstr($signature, ' :: '), 4);
        $message = (string) preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/', '“…”', $message);
        $message = (string) preg_replace('/\b[A-Za-z_]\w*(?:\\\\\w+)+/', 'Classe', $message);
        $message = (string) preg_replace('/-?\d+(?:\.\d+)?/', 'N', $message);

        return mb_substr($message, 0, 110);
    }

    /**
     * Méthode qui isole chaque problème en gardant l’en-tête de sa section
     *
     * @return array<string, string> signature => bloc de rapport ne contenant qu'un problème
     */
    private function blocks(string $output): array
    {
        $blocks = [];
        $header = '';
        $chunk = [];
        $emit = function () use (&$chunk, &$header, &$blocks): void {
            if ([] === $chunk) {
                return;
            }
            $text = implode("\n", $chunk);
            $signature = array_key_first(ProblemSignatures::of($text));
            if (null !== $signature) {
                $blocks[$signature] = ('' !== $header ? $header . "\n\n" : '') . $text;
            }
            $chunk = [];
        };

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (1 === preg_match('/^(There (?:was|were) \d+ .+:|ERREUR DE QUALITÉ .+:|\d+ tests? triggered .+:)$/', $line)) {
                $emit();
                $header = $line;
            } elseif (1 === preg_match('/^\d+\) /', $line)) {
                $emit();
                $chunk = [$line];
            } elseif (1 === preg_match('/^- Ligne \d+ : /', $line)) { // PHPStan : une ligne = un problème
                $emit();
                $chunk = [$line];
                $emit();
            } elseif ('--' === $line) {
                $emit();
            } elseif ([] !== $chunk) {
                $chunk[] = $line;
            }
        }
        $emit();

        return $blocks;
    }
}