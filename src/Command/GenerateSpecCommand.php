<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Llm\SpecGeneratorAgent;
use Mika\TestGeneratorBundle\Renderer\SpecMarkdownRenderer;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'make:generate:spec',
    description: 'Génère une matrice de spécification de tests au format Markdown pour une classe PHP.'
)]
class GenerateSpecCommand extends Command
{
    public function __construct(
        private readonly ClassResolver $classResolver,
        private readonly SpecGeneratorAgent $specGeneratorAgent,
        private readonly SpecMarkdownRenderer $markdownRenderer,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'class',
                InputArgument::REQUIRED,
                'Le FQCN ou le chemin relatif de la classe PHP à analyser'
            )
            ->addOption(
                'method', 'm',
                InputOption::VALUE_OPTIONAL,
                'Nom de la méthode spécifique à cibler'
            )
            ->addOption(
                'output-dir', 'o',
                InputOption::VALUE_OPTIONAL,
                'Dossier de destination pour le fichier Markdown', 'tests/Specs'
            )
            ->addOption(
                'model',
                null,
                InputOption::VALUE_OPTIONAL,
                'Modèle LLM spécifique à utiliser'
            )

            ->addOption(
                'provider',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Provider LLM spécifique à utiliser'
            )
            ->addOption(
                'dump-json',
                null,
                InputOption::VALUE_NONE,
                'Sauvegarde également le JSON brut généré par le LLM'
            );
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $classInput */
        $classInput = $input->getArgument('class');
        /** @var string|null $methodName */
        $methodName = $input->getOption('method');
        /** @var string $outputDir */
        $outputDir = $input->getOption('output-dir');
        /** @var string|null $model */
        $model = $input->getOption('model');
        /** @var string|null $provider */
        $provider = $input->getOption('provider');
        $dumpJson = (bool) $input->getOption('dump-json');

        // 1. Résolution de la classe ciblée
        try {
            /** @var array{className: string, filePath: string} $resolved */
            $resolved = $this->classResolver->resolve($classInput);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $fqcn = $resolved['className'];
        $filePath = $resolved['filePath'];

        // Extraction du nom court (ex : App\Service\VatCalculator → VatCalculator)
        $shortClassName = basename(str_replace('\\', '/', $fqcn));

        // Lecture du code source
        $classCode = file_get_contents($filePath);
        if (false === $classCode) {
            $io->error(sprintf('Impossible de lire le fichier : %s', $filePath));

            return Command::FAILURE;
        }

        $io->title(sprintf('Analyse de la classe : %s', $fqcn));

        if ($methodName) {
            $io->note(sprintf('Ciblage prioritaire de la méthode : %s()', $methodName));
        }

        // 2. Génération du JSON via SpecGeneratorAgent
        $io->section('Génération de la matrice de spécification par le LLM...');

        try {
            $specData = $this->specGeneratorAgent->generateSpec(
                classCode: $classCode,
                fqcn: $fqcn,
                methodName: $methodName,
                model: $model,
                provider: $provider
            );
        } catch (\Throwable $e) {
            $io->error(sprintf('Échec lors de la génération de la spec : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        // 3. Transformation en Markdown
        $markdownContent = $this->markdownRenderer->render($specData);

        // 4. Écriture du fichier Markdown
        $normalizedProjectDir = rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->projectDir), DIRECTORY_SEPARATOR
        );

        $normalizedOutputDir = trim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputDir), DIRECTORY_SEPARATOR
        );

        $targetDirectory = sprintf('%s%s%s', $normalizedProjectDir, DIRECTORY_SEPARATOR, $normalizedOutputDir);
        $this->filesystem->mkdir($targetDirectory);

        $baseFilename = sprintf('%sSpec.md', $shortClassName);
        $mdFilePath = sprintf('%s%s%s', $targetDirectory, DIRECTORY_SEPARATOR, $baseFilename);

        $this->filesystem->dumpFile($mdFilePath, $markdownContent);

        $io->success(sprintf('Spécification Markdown générée avec succès dans : %s', $mdFilePath));

        // Sauvegarde optionnelle du JSON brut
        if ($dumpJson) {
            $jsonFilePath = sprintf('%s/%s.json', $targetDirectory, $baseFilename);

            try {
                $jsonContent = json_encode(
                    $specData,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );

                $this->filesystem->dumpFile($jsonFilePath, $jsonContent);
                $io->info(sprintf('JSON brut sauvegardé dans : %s', $jsonFilePath));
            } catch (\JsonException $e) {
                $io->error(sprintf('Impossible de générer le JSON brut : %s', $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
