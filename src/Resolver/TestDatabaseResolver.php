<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mika\TestGeneratorBundle\Dto\DatabaseInfosDto;

readonly class TestDatabaseResolver
{
    public function __construct(
        private KernelInterface $kernel,
    ) {
    }

    /**
     * Identifie la plateforme du SGBD.
     */
    private function extractDatabasePlatform(Connection $connection): string
    {
        try {
            $platformClass = get_class($connection->getDatabasePlatform());

            return match (true) {
                str_contains($platformClass, 'PostgreSQL') => 'postgresql',
                str_contains($platformClass, 'MySQL') || str_contains($platformClass, 'MariaDB') => 'mysql',
                str_contains($platformClass, 'SQLite') => 'sqlite',
                default => strtolower(basename(str_replace('\\', '/', $platformClass))),
            };
        } catch (\Throwable) {
            /** @noinspection PhpInternalEntityUsedInspection */
            $params = $connection->getParams();
            $driver = $params['driver'] ?? '';

            return match (true) {
                str_contains($driver, 'pgsql') || str_contains($driver, 'postgres') => 'postgresql',
                str_contains($driver, 'mysql') || str_contains($driver, 'mariadb') => 'mysql',
                str_contains($driver, 'sqlite') => 'sqlite',
                default => 'unknown',
            };
        }
    }

    /**
     * Génère une DATABASE_URL de test à partir de celle de dev,
     * en vérifiant si Doctrine déclare un suffixe dans sa configuration pour l'environnement de test.
     * Ex : postgresql://db_user:db_pass@127.0.0.1:5432/app → postgresql://db_user:db_pass@127.0.0.1:5432/app_test.
     */
    public function suggestTestDatabaseUrl(bool $hasDbNameSuffix): ?string
    {
        $devUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
        if (!$devUrl) {
            return null;
        }

        // Si la chaîne contient déjà des query params, on sépare
        $parts = explode('?', $devUrl, 2);
        $baseUrl = $parts[0];
        $query = isset($parts[1]) ? '?' . $parts[1] : '';

        $lastSlash = strrpos($baseUrl, '/');
        if (false === $lastSlash) {
            return null;
        }

        // On récupère le nom de la base de DEV
        $dbName = substr($baseUrl, $lastSlash + 1);
        $urlWithoutDb = substr($baseUrl, 0, $lastSlash);

        // Si la configuration de Doctrine ne le fait pas déjà, on ajoute le suffixe '_test'.
        $testDbName = $hasDbNameSuffix ? $dbName : $dbName . '_test';

        return sprintf('%s/%s%s', $urlWithoutDb, $testDbName, $query);
    }

    public function getDatabaseInfosFromKernel(string $kernelClass, string $env): DatabaseInfosDto
    {
        $kernel = new $kernelClass($env, $this->kernel->isDebug());
        $kernel->boot();

        $container = $kernel->getContainer();
        if (!$container->has('doctrine')) {
            $kernel->shutdown();
            throw new \RuntimeException('DoctrineBundle n\'est pas configuré dans le projet hôte.');
        }

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');

        /** @var Connection $connection */
        $connection = $doctrine->getConnection();

        /** @var array<string, mixed> $params */
        /** @noinspection PhpInternalEntityUsedInspection */
        $params = $connection->getParams();

        /** @noinspection PhpInternalEntityUsedInspection */
        $hasSuffix = array_key_exists('dbname_suffix', $params) && !empty($params['dbname_suffix']);

        // On détermine la stratégie de mise à jour des tables
        $hasMigrations = $container->hasParameter('kernel.bundles')
            && isset($container->getParameter('kernel.bundles')['DoctrineMigrationsBundle']);

        $dbName = $this->getDbName($connection);
        $platform = $this->extractDatabasePlatform($connection);
        $dbExists = $this->checkDbExists($connection, $params, $platform, $dbName);

        $kernel->shutdown();

        return new DatabaseInfosDto(
            dbName: $dbName,
            platform: $platform,
            dbExists: $dbExists,
            hasMigrations: $hasMigrations,
            hasSuffix: $hasSuffix,
        );
    }

    public function getDatabaseInfosWithTestEnv(string $kernelClass, string $projectDir): DatabaseInfosDto
    {
        // Sauvegarde le contexte global du processus PHP
        $originalServer = $_SERVER;
        $originalEnv = $_ENV;

        try {
            // Nettoyage des variables en mémoire pour forcer le rechargement par Dotenv
            foreach (['DATABASE_URL', 'APP_ENV'] as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }

            // Charge explicitement les fichiers .env selon la cascade Symfony pour l'environnement 'test'
            $dotenv = new Dotenv();
            // NOTE : En environnement de test, le fichier `.env.local` est ignoré par Symfony
            $envFiles = [
                $projectDir . '/.env',
                $projectDir . '/.env.test',
                $projectDir . '/.env.test.local',
            ];

            foreach ($envFiles as $file) {
                if (file_exists($file)) {
                    $dotenv->overload($file);
                }
            }

            $_ENV['APP_ENV'] = 'test';
            $_SERVER['APP_ENV'] = 'test';

            // Récupère les informations de la BDD de TEST via un Kernel dédié
            $testDbInfos = $this->getDatabaseInfosFromKernel($kernelClass, 'test');
        } finally {
            // Restauration de l'état d'origine du processus CLI
            $_SERVER = $originalServer;
            $_ENV = $originalEnv;
        }

        return $testDbInfos;
    }

    private function getDbName(Connection $connection): string
    {
        try {
            // 1. Tentative d'obtention via la connexion active
            $dbName = $connection->getDatabase();
            if (!empty($dbName)) {
                return $dbName;
            }
        } catch (\Throwable) {
            // 2. Échec si la base n'existe pas encore sur le SGBD
        }

        // 3. Fallback : extraction depuis les paramètres (suppression de l'avertissement @internal)
        /** @noinspection PhpInternalEntityUsedInspection */
        $params = $connection->getParams();

        return $params['dbname'] ?? '';
    }

    private function checkDbExists(Connection $connection, array $params, string $platform, string $targetDbName): bool
    {
        if (empty($targetDbName)) {
            return false;
        }

        // 1. Tente une connexion directe sur la BDD cible via une requête neutre
        try {
            $testConn = DriverManager::getConnection($params, $connection->getConfiguration());
            $testConn->executeQuery('SELECT 1');
            $testConn->close();

            return true;
        } catch (\Throwable) {
            // Échec si la BDD n'existe pas encore
        }

        // 2. Interrogation des tables système d'administration en fonction du SGBD.
        try {
            $params['dbname'] = match ($platform) {
                'postgresql' => 'postgres',
                'mysql' => 'information_schema',
                default => null,
            };

            if (null === $params['dbname']) {
                return false;
            }

            $adminConn = DriverManager::getConnection($params, $connection->getConfiguration());

            $exists = match ($platform) {
                'postgresql' => (bool) $adminConn->fetchOne(
                    'SELECT 1 FROM pg_catalog.pg_database WHERE datname = ?',
                    [$targetDbName]
                ),
                'mysql' => (bool) $adminConn->fetchOne(
                    'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                    [$targetDbName]
                ),
                default => false,
            };

            $adminConn->close();

            return $exists;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{'success' => int, 'message' => string}
     */
    public function checkDatabaseNamesAreDifferent(DatabaseInfosDto $devDbInfos, DatabaseInfosDto $testDbInfos): array
    {
        // on s'assure de ne pas écraser la base de données de DEV existante
        if ($devDbInfos->dbExists && $devDbInfos->dbName === $testDbInfos->dbName) {
            $content = 'SÉCURITÉ : La base de données de test (%s) est IDENTIQUE à la base de développement (%s). '
                . 'Veuillez vérifier vos fichiers .env.test ou .env.test.local.';
            $message = sprintf($content, $testDbInfos->dbName, $devDbInfos->dbName);

            // Si les noms des bases sont identiques, on signale une erreur.
            return [
                'success' => Command::FAILURE,
                'message' => $message
            ];
        }

        // Sinon, c'est que la configuration est correcte.
        return [
            'success' => Command::SUCCESS,
            'message' => 'L\'environnement de test est correctement configuré.'
        ];
    }

    /**
     * @param string $strategy
     * @param string $projectDir
     * @param array $processEnv de l'environnement nettoyé
     * @return Process
     */
    public function handleDoctrineCommands(string $strategy, string $projectDir, array $processEnv): Process
    {
        match ($strategy) {
            'database:create' => $command = ['php', 'bin/console', 'doctrine:database:create', '--env=test', '--if-not-exists'],
            'migrations:migrate' => $command = ['php', 'bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction'],
            'schema:create' => $command = ['php', 'bin/console', 'doctrine:schema:create', '--env=test'],
        };

        $doctrineProcess = new Process(
            $command,
            $projectDir,
            $processEnv
        );

        $doctrineProcess->run();

        return $doctrineProcess;
    }

    /**
     * Crée ou met à jour le fichier .env.test.local et la DATABASE_URL de test.
     * Déclare la DATABASE_URL de test en utilisant la DATABASE_URL de dev.
     */
    public function createOrUpdateTestEnvFileAndDatabaseUrl(SymfonyStyle $io, string $projectDir, bool $hasDbNameSuffix): int
    {
        // Récupération de la DATABASE_URL de test
        $suggestedDatabaseUrl = $this->suggestTestDatabaseUrl($hasDbNameSuffix);

        // Si la DATABASE_URL de DEV n'a pas été trouvée ou qu'elle est invalide, on affiche un message d'erreur.
        if (null === $suggestedDatabaseUrl) {
            $io->error(
                'Impossible de créer le fichier .env.test.local : '
                .'la DATABASE_URL de DEV n\'a pas été trouvée ou est invalide.'
            );

            return Command::FAILURE;
        }

        $resolvedDatabaseUrl = 'DATABASE_URL="' . $suggestedDatabaseUrl . '"' . PHP_EOL;
        $envTestLocalPath = $projectDir . '/.env.test.local';

        // On demande à l'utilisateur si le fichier .env.test.local doit être configuré
        $message = 'Voulez-vous que le bundle configure le fichier .env.test.local pour déclarer une base de test ?';

        // Si l'utilisateur refuse de configurer le fichier .env.test.local, on affiche un message d'avertissement et on retourne une erreur.
        if (!$io->confirm($message)) {
            $io->warning('Configuration du fichier .env.test.local annulée.');
            $io->warning('Veuillez le créer manuellement ou relancer la commande.');

            return Command::FAILURE;
        }

        // Si le fichier n'existe pas
        if (!file_exists($envTestLocalPath)) {
            // On déclare lz contenu à ajouter dans le fichier .env.test.local
            $content = sprintf(
                '# Fichier généré automatiquement par TestGeneratorBundle' . PHP_EOL . '%s' . PHP_EOL,
                $resolvedDatabaseUrl
            );

            // On crée le fichier .env.test.local contenant la DATABASE_URL de test
            $isFileCreated = file_put_contents($envTestLocalPath, $content . PHP_EOL);

            // Si le fichier n'a pas pu être créé, on affiche un message d'erreur.
            if (false === $isFileCreated) {
                $io->error('Le fichier .env.test.local n\'a pas pu être créé.');

                return Command::FAILURE;
            }

            $io->success('Fichier .env.test.local créé avec succès !');

            return Command::SUCCESS;
        }

        // Le fichier .env.test.local existant déjà, on vérifie si la DATABASE_URL est définie.
        $content = file_get_contents($envTestLocalPath);

        // Regex pour cibler la déclaration de DATABASE_URL (active ou commentée)
        $pattern = '/^\s*#?\s*DATABASE_URL=.*$/m';

        if (preg_match($pattern, $content)) {
            // Si la variable existe déjà, on la remplace
            $updatedContent = preg_replace($pattern, $resolvedDatabaseUrl, $content);

            file_put_contents($envTestLocalPath, $updatedContent);
            $io->success('Le fichier .env.test.local a été mis à jour !');

            return Command::SUCCESS;
        }

        // Si la variable n'existe pas encore, on l'ajoute à la fin
        $prefix = (str_ends_with($content, "\n") || str_ends_with($content, "\r")) ? '' : PHP_EOL;
        $updatedContent = $content . $prefix . $resolvedDatabaseUrl . PHP_EOL;

        file_put_contents($envTestLocalPath, $updatedContent);
        $io->success('La DATABASE_URL a été ajoutée dans le fichier .env.test.local !');

        return Command::SUCCESS;
    }

    /**
     * Exécute la synchronisation de la base de test en appliquant les migrations ou en créant le schéma si nécessaire.
     */
    public function handleDoctrineSynchronisation(
        DatabaseInfosDto $testDbInfos,
        SymfonyStyle $io,
        string $projectDir,
        array $processEnv
    ): int {
        if ($testDbInfos->hasMigrations) {
            // Exécution des migrations Doctrine
            $io->text('Exécution des migrations Doctrine...');
            $migrateProcess = $this->handleDoctrineCommands('migrations:migrate', $projectDir, $processEnv);

            // On récupère les deux sorties, car certaines erreurs de migration s'affichent sur la sortie standard
            $outputLog = $migrateProcess->getErrorOutput() ?: $migrateProcess->getOutput();

            if ($migrateProcess->isSuccessful()) {
                $status = true;
                $actionLabel = 'migrée';
                // La migration ayant échoué, on se replie sur la création du schéma
            } elseif (str_contains($outputLog, 'no registered migrations')) {
                // Cas où le bundle de migration est installé, mais aucune migration n'existe encore
                $io->warning('Aucune classe de migration trouvée. Exécution de la commande doctrine:schema:create...');

                $schemaProcess = $this->handleDoctrineCommands('schema:create', $projectDir, $processEnv);

                $status = $schemaProcess->isSuccessful();
                $outputLog = $schemaProcess->getErrorOutput() ?: $schemaProcess->getOutput();
                $actionLabel = 'initialisée (schema:create)';
            } else {
                // Vraie erreur de migration
                $status = false;
                $actionLabel = 'migrée';
            }
        } else {
            // Génération du schéma
            $io->text('Génération du schéma via doctrine:schema:create...');
            $schemaProcess = $this->handleDoctrineCommands('schema:create', $projectDir, $processEnv);

            $status = $schemaProcess->isSuccessful();
            $outputLog = $schemaProcess->getErrorOutput() ?: $schemaProcess->getOutput();
            $actionLabel = 'initialisée (schema:create)';
        }

        // Si la vérification du schéma a échoué
        if (!$status) {
            $io->error("La préparation de la structure a échoué :\n" . $outputLog);

            return Command::FAILURE;
        }

        // Si la vérification du schéma a réussi
        $io->write($outputLog);
        $io->success(sprintf('Le schéma de la base "%s" a été %s avec succès !', $testDbInfos->dbName, $actionLabel));

        return Command::SUCCESS;
    }
}
