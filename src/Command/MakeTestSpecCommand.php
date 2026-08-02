<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:test-spec',
    description: 'Génère un fichier de spécification BDD pour orienter la génération d\'un test.',
)]
class MakeTestSpecCommand extends Command
{
    private string $bundleRootDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();

        // La racine du bundle se trouve deux niveaux au-dessus de Command
        $this->bundleRootDir = \dirname(__DIR__, 2);
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'class',
                InputArgument::REQUIRED,
                'Nom complet de la classe à tester (ex: App\\Service\\VatCalculator ou VatCalculator)'
            )
            ->addOption(
                'method',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Nom de la méthode ciblée'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $classNameInput = $input->getArgument('class');
        $methodName = $input->getOption('method');

        // Extraire le nom court de la classe (ex : App\Service\VatCalculator → VatCalculator).
        $shortClassName = basename(str_replace('\\', '/', $classNameInput));

        // Choix dynamique du template
        $templateName = $methodName
            ? 'test_spec_template.md'       // Spec ciblée sur une méthode
            : 'test_spec_class_template.md'; // Spec globale pour la classe

        // --- CHEMIN DU TEMPLATE ---
        // 1. Recherche prioritaire dans le projet hôte (surcharge utilisateur)
        $customTemplatePath = $this->projectDir
            . DIRECTORY_SEPARATOR . 'Resources'
            . DIRECTORY_SEPARATOR . 'spec-templates'
            . DIRECTORY_SEPARATOR . $templateName;

        // 2. Recherche fallback dans les assets natifs du bundle
        $nativeTemplatePath = $this->bundleRootDir
            . DIRECTORY_SEPARATOR . 'Resources'
            . DIRECTORY_SEPARATOR . 'spec-templates'
            . DIRECTORY_SEPARATOR . $templateName;

        $templatePath = file_exists($customTemplatePath) ? $customTemplatePath : $nativeTemplatePath;

        if (!file_exists($templatePath)) {
            $io->error(sprintf('Le template "%s" n\'existe pas.', $templatePath));

            return Command::FAILURE;
        }

        $templateContent = file_get_contents($templatePath);

        // Remplacement des variables dans le template
        $specContent = str_replace(
            ['{className}', '{methodName}'],
            [$shortClassName, $methodName ?? 'NomDeLaMethode'],
            $templateContent
        );

        // Détermination du nom de destination dans le PROJET HÔTE (tests/Specs).
        $specsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Specs';
        if (!is_dir($specsDir) && !mkdir($specsDir, 0777, true) && !is_dir($specsDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $specsDir));
        }

        // Résultat en partant de la racine du projet hôte : ./tests/Specs/VatCalculator_CalculateVTA
        $suffix = $methodName ? '_' . $methodName : '';
        $targetPath = sprintf('%s%s%s%sSpec.md', $specsDir, DIRECTORY_SEPARATOR, $shortClassName, $suffix);

        file_put_contents($targetPath, $specContent);

        $io->success(sprintf('Fichier de spécification généré avec succès : %s', $targetPath));
        $io->note(
            'Complétez ce fichier avec vos scénarios "Étant donné / Lorsque / Alors" '
            . 'puis passez-le à la commande de génération avec l\'option --spec.'
        );

        return Command::SUCCESS;
    }
}
