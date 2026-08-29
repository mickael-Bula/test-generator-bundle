<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
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
     * Résout le nom réel de la base de données de dev et de test.
     *
     * @return array{test_db: string, dev_db: string, platform: string}
     *
     * @throws Exception
     */
    public function resolveTestDatabaseInfo(): array
    {
        $projectDir = $this->kernel->getProjectDir();
        $kernelClass = get_class($this->kernel);

        // 1. Récupérer le nom de la BDD de DEV via un Kernel dédié
        $devKernel = new $kernelClass('dev', $this->kernel->isDebug());
        $devKernel->boot();
        $devDbName = $this->getDatabaseNameFromKernel($devKernel);
        $devKernel->shutdown();

        // 2. Sauvegarder le contexte global du processus PHP
        $originalServer = $_SERVER;
        $originalEnv = $_ENV;

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

            $testDbName = $connection->getDatabase();
            $platform = $this->extractDatabasePlatform($connection);

            $testKernel->shutdown();
        } catch (Exception $e) {
            $platform = 'unknown';
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
            throw new \RuntimeException(sprintf('SÉCURITÉ : La base de données de test (%s) est IDENTIQUE à la base de développement (%s). Veuillez vérifier vos fichiers .env.test ou .env.test.local.', $testDbName, $devDbName));
        }

        return [
            'test_db' => $testDbName,
            'dev_db' => $devDbName,
            'platform' => $platform,
        ];
    }

    /**
     * @throws Exception
     */
    private function getDatabaseNameFromKernel(KernelInterface $kernel): string
    {
        $container = $kernel->getContainer();
        if (!$container->has('doctrine')) {
            return '';
        }

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var Connection $connection */
        $connection = $doctrine->getConnection();

        return $connection->getDatabase() ?? '';
    }

    /**
     * @throws Exception
     */
    private function extractDatabasePlatform(Connection $connection): string
    {
        $platformClass = get_class($connection->getDatabasePlatform());

        return match (true) {
            str_contains($platformClass, 'PostgreSQL') => 'postgresql',
            str_contains($platformClass, 'MySQL') || str_contains($platformClass, 'MariaDB') => 'mysql',
            str_contains($platformClass, 'SQLite') => 'sqlite',
            default => strtolower(basename(str_replace('\\', '/', $platformClass))),
        };
    }
}
