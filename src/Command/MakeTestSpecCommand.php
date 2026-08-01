<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-spec',
    description: 'Génère un fichier de spécification BDD pour orienter la génération d\'un test.',
)]
class MakeTestSpecCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
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

        // Chemin du template
        $templatePath = $this->projectDir.'/templates/'.$templateName;
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

        // Détermination du nom de destination.
        $specsDir = $this->projectDir.'/tests/Specs';
        if (!is_dir($specsDir) && !mkdir($specsDir, 0777, true) && !is_dir($specsDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $specsDir));
        }

        // Résultat en partant de la racine du projet : ./tests/Specs/VatCalculator_CalculateVTA
        $suffix = $methodName ? '_'.$methodName : '';
        $targetPath = sprintf('%s/%s%sSpec.md', $specsDir, $shortClassName, $suffix);

        file_put_contents($targetPath, $specContent);

        $io->success(sprintf('Fichier de spécification généré avec succès : %s', $targetPath));
        $io->note('Complétez ce fichier avec vos scénarios "Étant donné / Lorsque / Alors" puis passez-le à la commande de génération avec l\'option --spec.');

        return Command::SUCCESS;
    }
}
