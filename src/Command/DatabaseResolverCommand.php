<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Symfony\Component\Dotenv\Dotenv;
use Mika\TestGeneratorBundle\Dto\DatabaseInfosDto;
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
     * Algo : Pour effectuer les tests fonctionnels, une base de test est nécessaire. Pour l'obtenir :
     * On vérifie si une base de test existe, sinon on la crée :
     *  1. Si la base de test existe et que son nom est différent de la base de Dev, alors la configuration est correcte.
     *  2. Sinon, on récupère la DATABASE_URL depuis le .env.test.local.
     *  3. Si la DATABASE_URL de DEV répond, on l'ajoute dans le fichier .env.test.local en adaptant son nom à la stratégie de Doctrine (suffixe "_test").
     *  4. On crée la base de test avec la commande doctrine.
     *  5. On exécute la mise à jour du schéma en fonction de la présence des migrations (si elles existent et qu'elles passent) avec d:m:m sinon on exécute doctrine:schema:create
     *
     * NOTE : On ignore le fichier .env.test dont les valeurs sont normalement générées par un outil de test (PHPUnit par exemple).
     *
     * @throws ExceptionInterface
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = $this->kernel->getProjectDir();
        $kernelClass = get_class($this->kernel);

        // Récupère les informations de la BDD de DEV via un Kernel dédié
        $devDbInfos = $this->resolver->getDatabaseInfosFromKernel($kernelClass, 'dev');

        // Récupère les informations de la BDD de TEST via un Kernel dédié
        $testDbInfos = $this->resolver->getDatabaseInfosWithTestEnv($kernelClass, $projectDir);

        // Si le moteur n'est pas PostgreSQL, on signale que seul ce moteur est pris en charge par le Bundle.
        if ('postgresql' !== $devDbInfos['platform']) {
            $this->displayMessage(
                $io,
                $devDbInfos,
                $testDbInfos,
                'warning',
                'Attention : seul le moteur PostgreSQL est pris en charge par le Bundle.'
            );

            return Command::SUCCESS;
        }

        // Si la base de TEST existe et porte un nom différent de la base de DEV...
        if ($testDbInfos['db_exists']) {
            // on s'assure de ne pas écraser la base de données de DEV existante
            if ($devDbInfos->dbExists && $devDbInfos->dbName === $testDbInfos->dbName) {
                $content = 'SÉCURITÉ : La base de données de test (%s) est IDENTIQUE à la base de développement (%s). '
                    . 'Veuillez vérifier vos fichiers .env.test ou .env.test.local.';
                $message = sprintf($content, $testDbInfos->dbName, $devDbInfos->dbName);

                // Si les noms des bases sont identiques, on signale une erreur.
                $this->displayMessage(
                    $io,
                    $devDbInfos,
                    $testDbInfos,
                    'error',
                    $message
                );

                return Command::FAILURE;
            }

            // Sinon, c'est que la configuration est correcte.
            $this->displayMessage(
                $io,
                $devDbInfos,
                $testDbInfos,
                'success',
                'L\'environnement de test est correctement configuré.'
            );

            return Command::SUCCESS;
        }

        // Si la base de DEV existe, on récupère sa DATABASE_URL pour créer la base de TEST.
        if ($devDbInfos->dbExists) {
            // Étape 1 : On s'assure que la DATABASE_URL est déclarée dans le fichier .env.test.local, en les créant ou en les mettant à jour.
            $envTestLocalPath = $projectDir . '/.env.test.local';
            $envTestLocalExists = file_exists($envTestLocalPath);

            // 1.1 : Si le fichier .env.test.local n'existe pas, on tente de le créer.
            if (!$envTestLocalExists) {
                // On affiche les informations des bases de données et on demande à l'utilisateur la permission de créer la base de test.
                $this->displayMessage(
                    $io,
                    $devDbInfos,
                    $testDbInfos,
                    'warning',
                    sprintf('La base de données de test "%s" n\'existe pas encore.', $testDbInfos->dbName)
                );

                if (Command::FAILURE === $this->resolver->createOrUpdateTestEnvFileAndDatabaseUrl($io, $projectDir, $testDbInfos->hasSuffix)) {
                    return Command::FAILURE;
                }
            }

            // 1.2 : On surcharge les variables d'environnement en mémoire avec le contenu du fichier '.env.test.local'
            if (file_exists($envTestLocalPath)) {
                (new Dotenv())->overload($envTestLocalPath);

                // Resynchronisation des variables pour le processus courant
                $_ENV['APP_ENV'] = 'test';
                $_SERVER['APP_ENV'] = 'test';

                // On rafraîchit les métadonnées de la base de TEST avec la nouvelle configuration
                $testDbInfos = $this->resolver->getDatabaseInfosFromKernel($kernelClass, 'test');
            }

            // Étape 2 : On demande à l'utilisateur s'il faut créer la base de test.
            if (!$io->confirm(sprintf('Voulez-vous créer la base de test %s ?', $testDbInfos->dbName))) {
                $io->info(sprintf('La création de la base de test %s a été annulée.', $testDbInfos->dbName));

                return Command::SUCCESS;
            }

            // 2.1 : Transmission explicite de la nouvelle DATABASE_URL rechargée depuis le fichier '.env.test.local'
            $processEnv = [
                'APP_ENV' => 'test',
                'DATABASE_URL' => $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'],
                'SYMFONY_DOTENV_VARS' => false,
            ];

            // 2.2 : Création de la base de données via l'Application Console
            $createDbProcess = $this->resolver->handleDoctrineCommands('database:create', $projectDir, $processEnv);

            if (!$createDbProcess->isSuccessful()) {
                $io->error("Erreur lors de la création de la base de données : \n" . $createDbProcess->getErrorOutput());

                return Command::FAILURE;
            }

            // 2.3 : On confirme la création de la base de test
            $io->success(sprintf('La base de test %s a été créée.', $testDbInfos->dbName));

            // Étape 3 : Exécution des migrations ou du schema:create en sous-processus isolé
            if (!$this->resolver->handleDoctrineSynchronisation($testDbInfos, $io, $projectDir, $processEnv)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Affiche dans le terminal les informations de la base de test et de la base de dev.
     */
    public function displayMessage(
        SymfonyStyle $io,
        DatabaseInfosDto $devDbInfos,
        DatabaseInfosDto $testDbInfos,
        string $type,
        string $message
    ): void {
        $io->table(
            ['Paramètre', 'Valeur'],
            [
                ['Moteur SGBD', $devDbInfos->platform],
                ['Base de DEV', $devDbInfos->dbName],
                ['Base de TEST', $testDbInfos->dbName],
                ['Existe sur le SGBD ?', $testDbInfos->dbExists ? 'Oui' : 'Non'],
            ]
        );
        match ($type) {
            'warning' => $io->warning($message),
            'error' => $io->error($message),
            'success' => $io->success($message),
        };
    }

//    /**
//     * Algo : Pour effectuer les tests foncionnels, une base de test est nécessaire. Pour l'obtenir :
//     * On vérifie si une base de test existe, sinon on la crée :
//     *  1. On recherche un `.env.test.local`. S'il n'existe pas, on le crée (point 4).
//     *  2. On y recherche la DATABASE_URL pour la tester. Si la variable n'existe pas, on la crée (point 4.).
//     *  3. On teste la chaîne récupérée. Si la base n'existe pas, on la crée (point 4).
//     *  4. On récupère la chaîne déclarée dans le .env.local. On vérifie la configuaration de doctrine (_test). On crée la base de test avec la commande doctrine
//     *  5. On exécute la mise à jour du schéma en fonction de la présence des migrations (si elles existent et qu'elles passent) avec d:m:m sinon on exécute doctrine:schema:create
//     *
//     * NOTE : On ignore le fichier .env.test dont les valeurs sont normalement générées par un outil de test (PHPUnit par exemple).
//     *
//     * @throws ExceptionInterface
//     */
//    protected function executeOld(InputInterface $input, OutputInterface $output): int
//    {
//        $io = new SymfonyStyle($input, $output);
//
//        $this->resolveDbInfos($input, $output);
//
//        return Command::SUCCESS;
//
//        // --------------------
//
//        // On vérifie la présence d'un fichier `.env.test.local`
//        $projectDir = $this->kernel->getProjectDir();
//        $envTestLocalExists = file_exists($projectDir . '/.env.test.local');
//
//        if (!$envTestLocalExists) {
//            $io->warning('Aucun fichier .env.test.local n\'a été trouvé dans le projet.');
//
//            $suggestedUrl = $this->resolver->suggestTestDatabaseUrl();
//
//            $message = 'Voulez-vous que le bundle crée un fichier .env.test.local pour déclarer une base de test ?';
//            if ($suggestedUrl && $io->confirm($message)) {
//                $content = sprintf(
//                    "# Fichier généré automatiquement par TestGeneratorBundle\nDATABASE_URL=\"%s\"\n",
//                    $suggestedUrl
//                );
//
//                file_put_contents($projectDir . '/.env.test.local', $content);
//                $io->success('Fichier .env.test.local créé avec succès !');
//            } else {
//                $io->note([
//                    'Veuillez créer un fichier .env.test.local à la racine de votre projet.',
//                    'Exemple de contenu :',
//                    'DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/db_test?serverVersion=16&charset=utf8"',
//                ]
//                );
//
//                return Command::FAILURE;
//            }
//        }
//
//        try {
//            $info = $this->resolver->resolveTestDatabaseInfo();
//        } catch (\RuntimeException $e) {
//            $io->error($e->getMessage());
//
//            return Command::FAILURE;
//        }
//
//        $io->table(
//            ['Paramètre', 'Valeur'],
//            [
//                ['Moteur SGBD', $info['platform']],
//                ['Base de DEV', $info['dev_db']],
//                ['Base de TEST', $info['test_db']],
//                ['Existe sur le SGBD ?', $info['test_db_exists'] ? 'Oui' : 'Non'],
//            ]
//        );
//
//        // Si le moteur n'est pas PostgreSQL, on signale que seul ce moteur est pris en charge.
//        if ('postgresql' !== $info['platform']) {
//            $io->warning('Attention : seul le moteur PostgreSQL est pris en charge pour les bases de test.');
//
//            return Command::SUCCESS;
//        }
//
//        // Interaction si la base n'existe pas
//        if (!$info['test_db_exists']) {
//            $io->warning(sprintf('La base de données de test "%s" n\'existe pas encore.', $info['test_db']));
//
//            if ($io->confirm('Voulez-vous exécuter la commande de création maintenant ?')) {
//                // On purge les variables pour obliger Symfony à relire complètement les fichiers `.env.test.*`
//                $processEnv = [
//                    'APP_ENV' => 'test',
//                    'DATABASE_URL' => false,
//                    'SYMFONY_DOTENV_VARS' => false, // Pour recharger les variables Dotenv
//                ];
//
//                // Étape 1 : Création de la base de données via l'Application Console
//                $createDbProcess = new Process(
//                    ['php', 'bin/console', 'doctrine:database:create', '--env=test', '--if-not-exists'],
//                    $projectDir,
//                    $processEnv, // Injection de l'environnement nettoyé
//                );
//                $createDbProcess->run();
//
//                if (!$createDbProcess->isSuccessful()) {
//                    $io->error("Erreur lors de la création de la base :\n" . $createDbProcess->getErrorOutput());
//
//                    return Command::FAILURE;
//                }
//
//                // On affiche la sortie de la commande pour la confirmer
//                $io->write($createDbProcess->getOutput());
//
//                // Étape 2 : Exécution des migrations ou du schema:create en sous-processus isolé
//                if ($info['has_migrations']) {
//                    $io->text('Exécution des migrations Doctrine...');
//                    $migrateProcess = new Process(
//                        ['php', 'bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction'],
//                        $projectDir,
//                        $processEnv, // Injection de l'environnement nettoyé
//                    );
//                    $migrateProcess->run();
//
//                    // On récupère les deux sorties, car certaines erreurs de migration s'affichent sur la sortie standard
//                    $outputLog = $migrateProcess->getErrorOutput() ?: $migrateProcess->getOutput();
//
//                    if ($migrateProcess->isSuccessful()) {
//                        $status = true;
//                        $actionLabel = 'migrée';
//                    } elseif (str_contains($outputLog, 'no registered migrations')) {
//                        // Cas où le bundle de migration est installé, mais aucune migration n'existe encore
//                        $io->warning('Aucune classe de migration trouvée. Exécution de la commande doctrine:schema:create...');
//
//                        $schemaProcess = new Process(
//                            ['php', 'bin/console', 'doctrine:schema:create', '--env=test'],
//                            $projectDir,
//                            $processEnv
//                        );
//                        $schemaProcess->run();
//
//                        $status = $schemaProcess->isSuccessful();
//                        $outputLog = $schemaProcess->getErrorOutput() ?: $schemaProcess->getOutput();
//                        $actionLabel = 'initialisée (schema:create)';
//                    } else {
//                        // Vraie erreur de migration
//                        $status = false;
//                        $actionLabel = 'migrée';
//                    }
//                } else {
//                    $io->text('Génération du schéma via doctrine:schema:create...');
//                    $schemaProcess = new Process(
//                        ['php', 'bin/console', 'doctrine:schema:create', '--env=test'],
//                        $projectDir,
//                        $processEnv
//                    );
//                    $schemaProcess->run();
//
//                    $status = $schemaProcess->isSuccessful();
//                    $outputLog = $schemaProcess->getErrorOutput() ?: $schemaProcess->getOutput();
//                    $actionLabel = 'initialisée (schema:create)';
//                }
//
//                if ($status) {
//                    $io->write($outputLog);
//                    $io->success(sprintf('La base "%s" a été créée et %s avec succès !', $info['test_db'], $actionLabel));
//                } else {
//                    $io->error("La préparation de la structure a échoué :\n" . $outputLog);
//
//                    return Command::FAILURE;
//                }
//            }
//        }
//
//        return Command::SUCCESS;
//    }
}
