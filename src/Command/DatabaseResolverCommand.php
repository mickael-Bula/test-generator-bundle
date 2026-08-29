<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Resolver\TestDatabaseResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'db:resolve',
    description: 'Résout le nom et le moteur de la base de données de test.'
)]
class DatabaseResolverCommand extends Command
{
    public function __construct(
        private readonly TestDatabaseResolver $resolver,
    ) {
        parent::__construct();
    }

    /**
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $info = $this->resolver->resolveTestDatabaseInfo();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Paramètre', 'Valeur'],
            [
                ['Moteur SGBD', $info['platform']],
                ['Base de DEV', $info['dev_db']],
                ['Base de TEST', $info['test_db']],
                ['Existe sur le SGBD ?', $info['test_db_exists'] ? 'Oui' : 'Non'],
            ]
        );

        // Interaction si la base n'existe pas
        if (!$info['test_db_exists']) {
            $io->warning(sprintf('La base de données de test "%s" n\'existe pas encore.', $info['test_db']));

            if ($io->confirm('Voulez-vous exécuter la commande de création maintenant ?')) {
                // Lancer la création via le process ou l'Application Console
                $command = $this->getApplication()->find('doctrine:database:create');
                $createInput = new ArrayInput(['--env' => 'test', '--if-not-exists' => true]);

                $returnCode = $command->run($createInput, $output);

                if (Command::SUCCESS === $returnCode) {
                    $io->success(sprintf('La base "%s" a été créée avec succès !', $info['test_db']));
                } else {
                    $io->error('Erreur lors de la création de la base de données.');

                    return Command::FAILURE;
                }
            }
        }

        return Command::SUCCESS;
    }
}
