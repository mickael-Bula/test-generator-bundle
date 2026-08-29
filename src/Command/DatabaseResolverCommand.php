<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Resolver\TestDatabaseResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'db:resolve',
    description: 'Résout le nom et le moteur de la base de données de test.'
)]
class DatabaseResolverCommand extends Command
{
    public function __construct(
        private readonly TestDatabaseResolver $resolver,
        private readonly KernelInterface $kernel,
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

        // Si le moteur n'est pas PostgreSQL, on signale que seul ce moteur est pris en charge.
        if ('postgresql' !== $info['platform']) {
            $io->warning('Attention : seul le moteur PostgreSQL est pris en charge pour les bases de test.');

            return Command::SUCCESS;
        }

        // Interaction si la base n'existe pas
        if (!$info['test_db_exists']) {
            $io->warning(sprintf('La base de données de test "%s" n\'existe pas encore.', $info['test_db']));

            if ($io->confirm('Voulez-vous exécuter la commande de création maintenant ?')) {
                $projectDir = $this->kernel->getProjectDir();

                // On purge les variables pour obliger Symfony à relire complètement les fichiers `.env.test.*`
                $processEnv = [
                    'APP_ENV' => 'test',
                    'DATABASE_URL' => false,
                    'SYMFONY_DOTENV_VARS' => false, // Pour recharger les caraibales Dotenv
                ];

                // Étape 1 : Création de la base de données via l'Applicaition Console
                $createDbProcess = new Process(
                    ['php', 'bin/console', 'doctrine:database:create', '--env=test', '--if-not-exists'],
                    $projectDir,
                    $processEnv, // Injection de l'environnement nettoyé
                );
                $createDbProcess->run();

                if (!$createDbProcess->isSuccessful()) {
                    $io->error("Erreur lors de la création de la base :\n" . $createDbProcess->getErrorOutput());

                    return Command::FAILURE;
                }

                // On affiche la sortie de la commande pour la confirmer
                $io->write($createDbProcess->getOutput());

                // Étape 2 : Exécution des migrations ou du schema:create en sous-processus isolé
                if ($info['has_migrations']) {
                    $io->text('Exécution des migrations Doctrine...');
                    $migrateProcess = new Process(
                        ['php', 'bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction'],
                        $projectDir,
                        $processEnv, // Injection de l'environnement nettoyé
                    );
                    $migrateProcess->run();

                    // On récupère les deux sorties, car certaines erreurs de migration s'affichent sur la sortie standard
                    $outputLog = $migrateProcess->getErrorOutput() ?: $migrateProcess->getOutput();

                    if ($migrateProcess->isSuccessful()) {
                        $status = true;
                        $actionLabel = 'migrée';
                    } elseif (str_contains($outputLog, 'no registered migrations')) {
                        // Cas où le bundle de migration est installé, mais aucune migration n'existe encore
                        $io->warning('Aucune classe de migration trouvée. Exécution de la commande doctrine:schema:create...');

                        $schemaProcess = new Process(
                            ['php', 'bin/console', 'doctrine:schema:create', '--env=test'],
                            $projectDir,
                            $processEnv
                        );
                        $schemaProcess->run();

                        $status = $schemaProcess->isSuccessful();
                        $outputLog = $schemaProcess->getErrorOutput() ?: $schemaProcess->getOutput();
                        $actionLabel = 'initialisée (schema:create)';
                    } else {
                        // Vraie erreur de migration
                        $status = false;
                        $actionLabel = 'migrée';
                    }
                } else {
                    $io->text('Génération du schéma via doctrine:schema:create...');
                    $schemaProcess = new Process(
                        ['php', 'bin/console', 'doctrine:schema:create', '--env=test'],
                        $projectDir,
                        $processEnv
                    );
                    $schemaProcess->run();

                    $status = $schemaProcess->isSuccessful();
                    $outputLog = $schemaProcess->getErrorOutput() ?: $schemaProcess->getOutput();
                    $actionLabel = 'initialisée (schema:create)';
                }

                if ($status) {
                    $io->write($outputLog);
                    $io->success(sprintf('La base "%s" a été créée et %s avec succès !', $info['test_db'], $actionLabel));
                } else {
                    $io->error("La préparation de la structure a échoué :\n" . $outputLog);

                    return Command::FAILURE;
                }
            }
        }

        return Command::SUCCESS;
    }
}
