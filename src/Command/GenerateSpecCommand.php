<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Enum\TestType;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Manager\SpecManager;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'llm:generate:spec',
    description: 'Génère une matrice de spécification de tests au format Markdown pour une classe PHP.'
)]
class GenerateSpecCommand extends Command
{
    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly ClassResolver $classResolver,
        private readonly SpecManager $specManager,
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
            )
            ->addOption(
                'unit',
                'u',
                InputOption::VALUE_NONE,
                'Générer un test unitaire (par défaut)'
            )
            ->addOption(
                'functional',
                'f',
                InputOption::VALUE_NONE,
                'Générer un test fonctionnel'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Écrase le fichier de spécification s\'il existe déjà sans demander confirmation'
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
            $resolved = $this->classResolver->resolve($classInput);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Traitement du type de test (unitaire ou fonctionnel)
        $isUnit = (bool) $input->getOption('unit');
        $isFunctional = (bool) $input->getOption('functional');

        // Validation pour empêcher d'activer les deux flags en même temps
        if ($isUnit && $isFunctional) {
            $io->error('Vous ne pouvez pas spécifier à la fois --unit (-u) et --functional (-f).');

            return Command::FAILURE;
        }

        // Détermination du type (par défaut : UNIT).
        $testType = $isFunctional ? TestType::FUNCTIONAL : TestType::UNIT;

        // Récupère le modèle passé en option, sinon celui déclaré par défaut dans les variables d'environnement
        $model = $model ?? $this->llmFactory->getDefaultModel();

        $fqcn = $resolved->className;
        $filePath = $resolved->filePath;

        // Extraction du nom seul (ex : App\Service\VatCalculator → VatCalculator)
        $shortClassName = basename(str_replace('\\', '/', $fqcn));

        // Vérification de l'existence du fichier source
        if (!is_file($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('Impossible de lire le fichier : %s', $filePath));

            return Command::FAILURE;
        }

        // Lecture du fichier source
        $classCode = file_get_contents($filePath);

        // Vérification de l'existence du fichier de spécification
        if ($this->specManager->hasSpec($shortClassName, $outputDir) && !$input->getOption('force')) {
            $specFilePath = $this->specManager->getSpecFilePath($shortClassName, $outputDir);
            $io->warning(sprintf('Le fichier de spécification "%s" existe déjà.', $specFilePath));

            if (!$io->confirm('Voulez-vous vraiment le remplacer ? L\'ancien contenu sera perdu.', false)) {
                $io->note('Génération annulée.');

                return Command::SUCCESS;
            }
        }

        $io->title(sprintf('Analyse de la classe : %s', $fqcn));

        // Affichage du type de test
        $io->comment(sprintf('Type de test : %s', $testType->label()));

        if ($methodName) {
            $io->note(sprintf('Ciblage prioritaire de la méthode : %s()', $methodName));
        }

        // Génération du JSON via SpecGeneratorAgent
        $io->section(sprintf('Génération de la matrice de spécification par le LLM (%s)...', $model));

        // Génération et écriture via SpecManager
        try {
            $result = $this->specManager->generateAndSaveSpec(
                shortClassName: $shortClassName,
                classCode: $classCode,
                fqcn: $fqcn,
                methodName: $methodName,
                testType: $testType->value,
                model: $model,
                provider: $provider,
                outputDir: $outputDir,
                dumpJson: $dumpJson
            );

            $io->success(
                sprintf('Fichier de spécification généré avec succès : %s', $result->getMarkdownFilePath())
            );
            $io->note(
                'Vous pouvez compléter ce fichier avec vos scénarios "Étant donné / Lorsque / Alors" '
                . 'afin qu\'il soit passés à la commande de génération.'
            );

            if ($result->jsonFilePath) {
                $io->info(sprintf('JSON brut sauvegardé dans : %s', $result->jsonFilePath));
            }
        } catch (TestGenerationException $e) {
            // Erreur d'appel API / LLM
            $io->error(sprintf('Erreur LLM lors de la génération : %s', $e->getMessage()));

            return Command::FAILURE;
        } catch (\JsonException $e) {
            // Erreur de formatage du JSON
            $io->error(sprintf('Impossible de formater la spécification en JSON : %s', $e->getMessage()));

            return Command::FAILURE;
        } catch (\Throwable $e) {
            // Pour toute autre erreur
            $io->error(sprintf('Échec lors de la génération de la spécification : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
