<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\KernelInterface;

readonly class TestDatabaseResolver
{
    public function __construct(
        private KernelInterface $kernel,
    ) {
    }

    /**
     * Résout le nom réel des BDD de dev et test, et vérifie leur existence sur le serveur SGBD.
     *
     * @return array{
     *     test_db: string,
     *     dev_db: string,
     *     platform: string,
     *     dev_db_exists: bool,
     *     test_db_exists: bool
     * }
     */
    public function resolveTestDatabaseInfo(): array
    {
        $projectDir = $this->kernel->getProjectDir();
        $kernelClass = get_class($this->kernel);

        // 1. Récupérer les informations de la BDD de DEV via un Kernel dédié
        $devKernel = new $kernelClass('dev', $this->kernel->isDebug());
        $devKernel->boot();

        $devDbName = $this->resolveDatabaseNameFromKernel($devKernel);
        $devDbExists = false;

        if ($devKernel->getContainer()->has('doctrine')) {
            /** @var ManagerRegistry $devDoctrine */
            $devDoctrine = $devKernel->getContainer()->get('doctrine');
            /** @var Connection $devConnection */
            $devConnection = $devDoctrine->getConnection();
            $devDbExists = $this->checkDatabaseExists($devConnection, $devDbName);
        }

        $devKernel->shutdown();

        // 2. Sauvegarder le contexte global du processus PHP
        $originalServer = $_SERVER;
        $originalEnv = $_ENV;

        $testDbName = '';
        $platform = 'unknown';
        $testDbExists = false;

        try {
            // Nettoyage des variables en mémoire pour forcer le rechargement par Dotenv
            foreach (['DATABASE_URL', 'APP_ENV'] as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }

            // 3. Charger explicitement les fichiers .env selon la cascade Symfony pour l'environnement 'test'
            $dotenv = new Dotenv();
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

            // 4. Démarrer le Kernel de TEST et extraire le nom résolu par Doctrine
            $testKernel = new $kernelClass('test', $this->kernel->isDebug());
            $testKernel->boot();

            $testContainer = $testKernel->getContainer();
            if (!$testContainer->has('doctrine')) {
                $testKernel->shutdown();
                throw new \RuntimeException('DoctrineBundle n\'est pas configuré dans le projet hôte.');
            }

            /** @var ManagerRegistry $doctrine */
            $doctrine = $testContainer->get('doctrine');
            /** @var Connection $connection */
            $connection = $doctrine->getConnection();

            $testDbName = $this->resolveDatabaseNameFromKernel($testKernel);
            $platform = $this->extractDatabasePlatform($connection);
            $testDbExists = $this->checkDatabaseExists($connection, $testDbName);

            $testKernel->shutdown();
        } finally {
            // Restauration de l'état d'origine du processus CLI
            $_SERVER = $originalServer;
            $_ENV = $originalEnv;
        }

        // 5. Contrôle de sécurité
        if (empty($testDbName)) {
            throw new \RuntimeException('Impossible de résoudre le nom de la base de données de test.');
        }

        if ($devDbName === $testDbName) {
            $message = 'SÉCURITÉ : La base de données de test (%s) est IDENTIQUE à la base de développement (%s). '
                . 'Veuillez vérifier vos fichiers .env.test ou .env.test.local.';
            throw new \RuntimeException(sprintf($message, $testDbName, $devDbName));
        }

        return [
            'test_db' => $testDbName,
            'dev_db' => $devDbName,
            'platform' => $platform,
            'dev_db_exists' => $devDbExists,
            'test_db_exists' => $testDbExists,
        ];
    }

    /**
     * Résout le nom de la BDD en utilisant la méthode officielle Doctrine `$connection->getDatabase()`.
     * En cas d'échec de connexion (BDD inexistante sur PostgreSQL), extrait proprement la valeur depuis les paramètres.
     */
    private function resolveDatabaseNameFromKernel(KernelInterface $kernel): string
    {
        $container = $kernel->getContainer();
        if (!$container->has('doctrine')) {
            return '';
        }

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var Connection $connection */
        $connection = $doctrine->getConnection();

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

    /**
     * Vérifie si la BDD existe réellement en interrogeant le dictionnaire système du SGBD.
     */
    private function checkDatabaseExists(Connection $connection, string $targetDbName): bool
    {
        if (empty($targetDbName)) {
            return false;
        }

        /** @noinspection PhpInternalEntityUsedInspection */
        $params = $connection->getParams();
        $platform = $this->extractDatabasePlatform($connection);

        // 1. Tenter une connexion directe sur la BDD cible via une requête neutre
        try {
            $testConn = DriverManager::getConnection($params, $connection->getConfiguration());
            $testConn->executeQuery('SELECT 1');
            $testConn->close();

            return true;
        } catch (\Throwable) {
            // Échec si la BDD n'existe pas encore
        }

        // 2. Interrogation des tables système d'administration
        try {
            $adminParams = $params;

            $adminParams['dbname'] = match ($platform) {
                'postgresql' => 'postgres',
                'mysql' => 'information_schema',
                default => null,
            };

            if (null === $adminParams['dbname']) {
                return false;
            }

            $adminConn = DriverManager::getConnection($adminParams, $connection->getConfiguration());

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
}
