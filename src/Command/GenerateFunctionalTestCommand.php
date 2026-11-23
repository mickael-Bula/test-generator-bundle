<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Functional\Choice\NullStepChooser;
use Mika\TestGeneratorBundle\Functional\Enum\GenerationStatus;
use Mika\TestGeneratorBundle\Functional\Generation\FunctionalTestGenerator;
use Mika\TestGeneratorBundle\Functional\Generation\GeneratedTestPath;
use Mika\TestGeneratorBundle\Functional\Generation\GenerationReportFormatter;
use Mika\TestGeneratorBundle\Functional\Render\RenderOptions;
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

    public function __construct(
        private readonly FunctionalTestGenerator $generator,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly GenerationReportFormatter $formatter = new GenerationReportFormatter(),
        private readonly GeneratedTestPath $paths = new GeneratedTestPath(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'scenario',
                InputArgument::REQUIRED,
                'Chemin du scénario Markdown'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Fichier .php ou dossier de sortie (défaut : déduit du namespace)'
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'Namespace de la classe générée',
                'App\Tests\Dynamic\Functional'
            )
            ->addOption(
                'base-class',
                null,
                InputOption::VALUE_REQUIRED,
                'Classe de base des tests E2E de l\'hôte',
                'App\Tests\E2E\E2ETest'
            )
            ->addOption(
                'class',
                null,
                InputOption::VALUE_REQUIRED,
                'Nom de la classe générée (défaut : dérivé du titre du scénario)'
            )
            ->addOption(
                'no-llm',
                null,
                InputOption::VALUE_NONE,
                'Ne pas solliciter le LLM : s\'arrêter et lister les candidats aux étapes ambiguës'
            )
            ->addOption(
                'max-decisions',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre maximal d\'étapes tranchées par le LLM', '5'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Afficher le test au lieu de l\'écrire'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Écraser le fichier s\'il existe'
            )
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

        try {
            $path = $this->paths->resolve(
                $this->projectDir,
                $options->namespace,
                $report->className,
                $this->nullIfEmpty($input->getOption('output'))
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (is_file($path) && true !== $input->getOption('force')) {
            $output->writeln(sprintf(
                '<error>Le fichier existe déjà : %s (utilisez --force pour l\'écraser, ou --dry-run pour l\'afficher).</error>',
                $path
            ));

            return Command::FAILURE;
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

        return Command::SUCCESS;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
