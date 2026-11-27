<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Functional\Choice\NullStepChooser;
use Mika\TestGeneratorBundle\Functional\Enum\GenerationStatus;
use Mika\TestGeneratorBundle\Functional\Exception\VerificationException;
use Mika\TestGeneratorBundle\Functional\Generation\FunctionalTestGenerator;
use Mika\TestGeneratorBundle\Functional\Generation\GeneratedTestDelivery;
use Mika\TestGeneratorBundle\Functional\Generation\GeneratedTestPath;
use Mika\TestGeneratorBundle\Functional\Generation\GenerationReportFormatter;
use Mika\TestGeneratorBundle\Functional\Render\RenderOptions;
use Mika\TestGeneratorBundle\Functional\Verification\GeneratedTestRunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'llm:generate:functional:test',
    description: 'Génère un test fonctionnel (navigateur) à partir d\'un scénario Markdown.',
)]
final class GenerateFunctionalTestCommand extends Command
{
    public const EXIT_NEEDS_DECISION = 2;
    public const DEFAULT_GREEN_DIR = 'tests/Functional';
    public const DEFAULT_FAILED_DIR = 'var/failed_tests/functional';

    public function __construct(
        private readonly FunctionalTestGenerator $generator,
        private readonly GeneratedTestRunnerInterface $runner,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly GenerationReportFormatter $formatter = new GenerationReportFormatter(),
        private readonly GeneratedTestPath $paths = new GeneratedTestPath(),
        private readonly GeneratedTestDelivery $delivery = new GeneratedTestDelivery(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('scenario', InputArgument::REQUIRED, 'Chemin du scénario Markdown')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Fichier .php ou dossier de sortie : le test y reste, sans déplacement (défaut : zone d\'attente déduite du namespace)')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace de la classe générée', 'App\Tests\Dynamic\Functional')
            ->addOption('base-class', null, InputOption::VALUE_REQUIRED, 'Classe de base des tests E2E de l\'hôte', 'App\Tests\E2E\E2ETest')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Nom de la classe générée (défaut : dérivé du titre du scénario)')
            ->addOption('no-llm', null, InputOption::VALUE_NONE, 'Ne pas solliciter le LLM : s\'arrêter et lister les candidats aux étapes ambiguës')
            ->addOption('max-decisions', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d\'étapes tranchées par le LLM', '5')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher le test au lieu de l\'écrire')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Écraser le fichier s\'il existe')
            ->addOption('no-run', null, InputOption::VALUE_NONE, 'Ne pas lancer PHPUnit : écrire le test dans la zone d\'attente et s\'arrêter')
            ->addOption('green-dir', null, InputOption::VALUE_REQUIRED, 'Dossier des tests verts (relatif au projet)', self::DEFAULT_GREEN_DIR)
            ->addOption('failed-dir', null, InputOption::VALUE_REQUIRED, 'Dossier des tests en échec, avec leur journal (relatif au projet)', self::DEFAULT_FAILED_DIR)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scenario = (string) $input->getArgument('scenario');
        $maxDecisions = filter_var($input->getOption('max-decisions'), FILTER_VALIDATE_INT);
        if (false === $maxDecisions || $maxDecisions < 0) {
            $output->writeln('<error>--max-decisions doit être un entier positif ou nul.</error>');

            return Command::FAILURE;
        }

        $options = new RenderOptions(
            (string) $input->getOption('namespace'),
            (string) $input->getOption('base-class'),
            $this->nullIfEmpty($input->getOption('class')),
        );

        $report = $this->generator->generate(
            $scenario,
            $options,
            true === $input->getOption('no-llm') ? new NullStepChooser() : null,
            $maxDecisions,
        );

        foreach ($this->formatter->lines($report) as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        if (GenerationStatus::NeedsDecision === $report->status) {
            $output->writeln('<comment>Aucun test généré : une étape reste à trancher.</comment>');

            return self::EXIT_NEEDS_DECISION;
        }

        if (GenerationStatus::Failed === $report->status || null === $report->source) {
            $output->writeln('<error>Aucun test généré.</error>');

            return Command::FAILURE;
        }

        if (true === $input->getOption('dry-run')) {
            $output->writeln('');
            $output->writeln($report->source, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $explicitOutput = $this->nullIfEmpty($input->getOption('output'));
        $force = true === $input->getOption('force');
        $run = true !== $input->getOption('no-run');
        $moves = $run && null === $explicitOutput;

        try {
            $path = $this->paths->resolve($this->projectDir, $options->namespace, $report->className, $explicitOutput);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $greenDir = $this->paths->directory($this->projectDir, (string) $input->getOption('green-dir'));
        $failedDir = $this->paths->directory($this->projectDir, (string) $input->getOption('failed-dir'));

        foreach ($this->conflicts($path, $moves ? $greenDir . '/' . $report->className . '.php' : null) as $existing) {
            if (!$force) {
                $output->writeln(sprintf('<error>Le fichier existe déjà : %s (utilisez --force pour l\'écraser, ou --dry-run pour l\'afficher).</error>', $existing));

                return Command::FAILURE;
            }
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Impossible de créer le dossier : %s</error>', $directory));

            return Command::FAILURE;
        }

        if (false === file_put_contents($path, $report->source)) {
            $output->writeln(sprintf('<error>Impossible d\'écrire : %s</error>', $path));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Test écrit : %s</info>', $path));

        if (!$run) {
            return Command::SUCCESS;
        }

        return $this->verify($output, $path, $moves, $greenDir, $failedDir, $options->baseClass, $force);
    }

    /**
     * Lance PHPUnit sur le test écrit, puis, sauf --output, le sort de la zone d'attente selon le résultat.
     */
    private function verify(OutputInterface $output, string $path, bool $moves, string $greenDir, string $failedDir, string $baseClass, bool $force): int
    {
        $output->writeln('Exécution de PHPUnit sur le test généré…');

        try {
            $result = $this->runner->run($path);
        } catch (VerificationException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln(sprintf('<comment>Le test n\'a pas pu être vérifié ; il reste en place : %s</comment>', $path));

            return Command::FAILURE;
        }

        try {
            if ($result->passed) {
                if (!$moves) {
                    $output->writeln('<info>PHPUnit : vert. Test conservé : ' . $path . '</info>');

                    return Command::SUCCESS;
                }

                $target = $this->delivery->promote($this->projectDir, $path, $greenDir, $baseClass, $force);
                $output->writeln('<info>PHPUnit : vert. Test déplacé : ' . $target . '</info>');

                return Command::SUCCESS;
            }

            $output->writeln('<error>PHPUnit : rouge' . ($result->timedOut ? ' (délai dépassé)' : '') . '.</error>');
            $output->writeln("Sortie de PHPUnit (fin) :\n" . $result->tail(), OutputInterface::OUTPUT_RAW);

            if (!$moves) {
                $output->writeln('<comment>Test conservé : ' . $path . '</comment>');

                return Command::FAILURE;
            }

            [$target, $log] = $this->delivery->quarantine($path, $failedDir, $result->output);
            $output->writeln('<comment>Test mis de côté : ' . $target . '</comment>');
            $output->writeln('<comment>Journal PHPUnit  : ' . $log . '</comment>');
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln(sprintf('<comment>Le test reste dans la zone d\'attente : %s</comment>', $path));
        }

        return Command::FAILURE;
    }

    /**
     * @return list<string> fichiers déjà présents qu'une exécution écraserait
     */
    private function conflicts(string $path, ?string $greenPath): array
    {
        return array_values(array_filter([$path, $greenPath], static fn (?string $file): bool => null !== $file && is_file($file)));
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
