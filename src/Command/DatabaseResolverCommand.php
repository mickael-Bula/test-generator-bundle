<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Resolver\TestDatabaseResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $info = $this->resolver->resolveTestDatabaseInfo();

            $io->success(sprintf('La base de test se nomme : %s', $info['test_db']));

            $io->table(
                ['Paramètre', 'Valeur'],
                [
                    ['Moteur SGBD', $info['platform']],
                    ['Base de DEV', $info['dev_db']],
                    ['Base de TEST', $info['test_db']],
                ]
            );
        } catch (\Exception $e) {
            $io->error(sprintf('Erreur lors de la résolution : %s', $e->getMessage()));

            return Command::FAILURE;
        } catch (\Doctrine\DBAL\Exception $e) {
        }

        return Command::SUCCESS;
    }
}
