<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Dto\DatabaseInfosDto;
use Mika\TestGeneratorBundle\Resolver\TestDatabaseResolver;
use Mika\TestGeneratorBundle\Resolver\DamaTestBundleResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'db:resolve',
    description: 'Résout le nom et le moteur de la base de données de test.'
)]
class DatabaseResolverCommand extends Command
{
    public function __construct(
        private readonly TestDatabaseResolver $resolver,
        private readonly KernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly DamaTestBundleResolver $damaResolver,
    ) {
        parent::__construct();
    }


    /**
     * Algo : Pour effectuer les tests fonctionnels, une base de test est nécessaire. Pour l'obtenir :
     * On vérifie si une base de test existe, sinon on la crée :
     *  1. Si la base de test existe et que son nom est différent de la base de Dev, alors la configuration est correcte.
     *  2. Sinon, on récupère la DATABASE_URL depuis le `.env.test.local`.
     *  3. Si la DATABASE_URL de DEV répond, on l'ajoute dans le fichier .env.test.local en adaptant son nom à la stratégie de Doctrine (suffixe "_test").
     *  4. On crée la base de test avec la commande doctrine.
     *  5. On exécute la mise à jour du schéma en fonction de la présence des migrations :
     *      - si elles existent, on lance la commande `doctrine:migrations:migrate`. Si les ne passent pas, on lance `doctrine:schema:create`.
     *      - sinon on exécute `doctrine:schema:create`
     *
     * NOTE 1 : On ignore le fichier `.env.test` dont les valeurs sont normalement générées par un outil de test (PHPUnit par exemple).
     * NOTE 2 : Le fichier `.env.test.local` est ignoré par Symfony en environnement de test.
     *
     * @throws ExceptionInterface
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $kernelClass = get_class($this->kernel);

        // Vérification préalable du bundle DAMA
        if (!$this->damaResolver->checkDamaBundle($io, $this->projectDir)) {
            return Command::FAILURE;
        };

        // Récupère les informations de la BDD de DEV via un Kernel dédié
        $devDbInfos = $this->resolver->getDatabaseInfosFromKernel($kernelClass, 'dev');

        // Récupère les informations de la BDD de TEST via un Kernel dédié
        $testDbInfos = $this->resolver->getDatabaseInfosWithTestEnv($kernelClass, $this->projectDir);

        // Si le moteur n'est pas PostgreSQL, on signale que seul ce moteur est pris en charge par le Bundle.
        if ('postgresql' !== $devDbInfos->platform) {
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
        if ($testDbInfos->dbExists) {
            // On vérifie que les noms des bases ne sont pas identiques.
            $checkDbNames = $this->resolver->checkDatabaseNamesAreDifferent($devDbInfos, $testDbInfos);

            // On affiche le résultat en console.
            $this->displayMessage(
                $io,
                $devDbInfos,
                $testDbInfos,
                $checkDbNames['success'] === Command::SUCCESS ? 'success' : 'error',
                $checkDbNames['message']
            );

            return $checkDbNames['success'] === Command::SUCCESS ? Command::SUCCESS : Command::FAILURE;
        }

        // Si la base de DEV existe, on récupère sa DATABASE_URL pour créer la base de TEST.
        if ($devDbInfos->dbExists) {
            // Étape 1 : On s'assure que la DATABASE_URL est déclarée dans le fichier .env.test.local, en les créant ou en les mettant à jour.
            $envTestLocalPath = $this->projectDir . '/.env.test.local';
            $envTestLocalExists = file_exists($envTestLocalPath);

            // 1.1 : Si le fichier .env.test.local n'existe pas, on tente de le créer.
            if (!$envTestLocalExists) {
                // On affiche les informations des bases de données et on demande à l'utilisateur la permission de créer la base de test.
                $this->displayMessage(
                    $io,
                    $devDbInfos,
                    $testDbInfos,
                    'warning',
                    sprintf('La base de données de test "%s" n\'existe pas encore.', $devDbInfos->dbName . '_test')
                );

                if (Command::FAILURE === $this->resolver->createOrUpdateTestEnvFileAndDatabaseUrl($io, $this->projectDir, $testDbInfos->hasSuffix)) {
                    return Command::FAILURE;
                }
            }

            // 1.2 : On surcharge les variables d'environnement en mémoire avec le contenu du fichier '.env.test.local'
            (new Dotenv())->overload($envTestLocalPath);

            // 1.3 Resynchronisation des variables pour le processus courant
            $_ENV['APP_ENV'] = 'test';
            $_SERVER['APP_ENV'] = 'test';

            // 1.4 On rafraîchit les métadonnées de la base de TEST avec la nouvelle configuration
            $testDbInfos = $this->resolver->getDatabaseInfosFromKernel($kernelClass, 'test');

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
            $createDbProcess = $this->resolver->handleDoctrineCommands('database:create', $this->projectDir, $processEnv);

            if (!$createDbProcess->isSuccessful()) {
                $io->error("Erreur lors de la création de la base de données : \n" . $createDbProcess->getErrorOutput());

                return Command::FAILURE;
            }

            // 2.3 : On confirme la création de la base de test
            $io->success(sprintf('La base de test %s a été créée.', $testDbInfos->dbName));

            // Étape 3 : Exécution des migrations ou du schema:create en sous-processus isolé
            if (Command::FAILURE === $this->resolver->handleDoctrineSynchronisation($testDbInfos, $io, $this->projectDir, $processEnv)) {
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
}
