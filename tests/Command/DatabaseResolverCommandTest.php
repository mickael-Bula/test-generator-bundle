<?php

declare(strict_types=1);

namespace Command;

use Mika\TestGeneratorBundle\Command\DatabaseResolverCommand;
use Mika\TestGeneratorBundle\Dto\DatabaseInfosDto;
use Mika\TestGeneratorBundle\Resolver\TestDatabaseResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

#[CoversClass(DatabaseResolverCommand::class)]
final class DatabaseResolverCommandTest extends TestCase
{
    private MockObject|TestDatabaseResolver $resolver;
    private MockObject|KernelInterface $kernel;
    private CommandTester $commandTester;
    private string $tempDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(TestDatabaseResolver::class);
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/test_' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);

        $this->kernel->method('getProjectDir')->willReturn($this->tempDir);

        $command = new DatabaseResolverCommand($this->resolver, $this->kernel);
        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->chmod($this->tempDir, 0777, 0000, true);
            $this->filesystem->remove($this->tempDir);
        }
    }

    private function createValidEnvFile(): void
    {
        $dbUrl = 'postgresql://postgres:postgres@127.0.0.1:5432/app_test?serverVersion=16&charset=utf8';

        $this->filesystem->dumpFile(
            $this->tempDir . '/.env.test.local',
            sprintf('DATABASE_URL="%s"', $dbUrl)
        );

        $_ENV['DATABASE_URL'] = $dbUrl;
        $_SERVER['DATABASE_URL'] = $dbUrl;
    }

    #[Test]
    public function testExecuteReturnsSuccessWhenPlatformIsNotPostgreSQL(): void
    {
        // ÉTANT DONNÉ
        $devInfos = new DatabaseInfosDto('dev_db', 'mysql', true, true, false);
        $testInfos = new DatabaseInfosDto('test_db', 'mysql', true, true, true);
        $this->resolver->method('getDatabaseInfosFromKernel')->willReturn($devInfos);
        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);

        // QUAND
        $exitCode = $this->commandTester->execute([]);

        // ALORS
        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function testExecuteSuccessWhenTestDbExistsAndNamesDiffer(): void
    {
        // ÉTANT DONNÉ
        $devInfos = new DatabaseInfosDto('dev_db', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('test_db', 'postgresql', true, true, true);
        $this->resolver->method('getDatabaseInfosFromKernel')->willReturn($devInfos);
        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);
        $this->resolver->method('checkDatabaseNamesAreDifferent')->willReturn(['success' => Command::SUCCESS, 'message' => 'OK']);

        // QUAND
        $exitCode = $this->commandTester->execute([]);

        // ALORS
        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function testExecuteFailureWhenTestDbExistsAndNamesAreSame(): void
    {
        // ÉTANT DONNÉ
        $devInfos = new DatabaseInfosDto('app_dev', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('app_dev', 'postgresql', true, true, true);
        $this->resolver->method('getDatabaseInfosFromKernel')->willReturn($devInfos);
        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);
        $this->resolver->method('checkDatabaseNamesAreDifferent')->willReturn(['success' => Command::FAILURE, 'message' => 'Identiques']);

        // QUAND
        $exitCode = $this->commandTester->execute([]);

        // ALORS
        $this->assertSame(Command::FAILURE, $exitCode);
    }

    #[Test]
    public function testExecuteReturnsFailureWhenEnvFileCreationFails(): void
    {
        // ÉTANT DONNÉ
        $devInfos = new DatabaseInfosDto('dev_db', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('app_test', 'postgresql', false, true, false);
        $this->resolver->method('getDatabaseInfosFromKernel')->willReturn($devInfos);
        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);
        $this->resolver->method('createOrUpdateTestEnvFileAndDatabaseUrl')->willReturn(Command::FAILURE);

        // QUAND
        $exitCode = $this->commandTester->execute([]);

        // ALORS
        $this->assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * Teste l'annulation de la création de la base de données par l'utilisateur (réponse "no").
     *
     * PIÈGES & POINTS D'ATTENTION LORS DE CE TEST :
     * 1. Fichier .env.test.local (PathException) :
     *    - La commande tente de recharger les variables via (new Dotenv())->overload().
     *    - Sans la présence physique du fichier sur le disque ($this->createValidEnvFile()),
     *      Dotenv lève une PathException. Le fichier doit donc exister pour le test.
     * 2. Signature dynamique de getDatabaseInfosFromKernel :
     *    - La commande appelle getDatabaseInfosFromKernel d'abord avec 'dev', puis plus tard
     *      avec 'test' après le rechargement des variables d'environnement.
     *    - L'utilisation d'un callback willReturnCallback() évite d'épuiser les mocks ou de
     *      retourner NULL sur les appels successifs.
     * 3. Interaction avec CommandTester (setInputs) :
     *    - $this->commandTester->setInputs(['no']) préremplit le flux STDIN pour répondre
     *      au prompt $io->confirm().
     *    - Si l'alignement des questions change dans le code source, le tableau d'entrées
     *      doit refléter exactement la séquence des prompts posés.
     */
    #[Test]
    public function testExecuteReturnsSuccessWhenUserCancelsDatabaseCreation(): void
    {
        // ÉTANT DONNÉ : On crée un fichier .env.test.local physique
        // obligatoire pour éviter une PathException lors du (new Dotenv())->overload()
        $this->createValidEnvFile();

        $devInfos = new DatabaseInfosDto('dev_db', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('app_test', 'postgresql', false, true, false);

        // On utilise un callback pour gérer dynamiquement les appels pour 'dev' et 'test'
        $this->resolver->method('getDatabaseInfosFromKernel')
            ->willReturnCallback(function (string $kernelClass, string $env) use ($devInfos, $testInfos) {
                return 'dev' === $env ? $devInfos : $testInfos;
            });

        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);

        // QUAND : On simule la réponse "no" au prompt de confirmation du SymfonyStyle
        $this->commandTester->setInputs(['no']);
        $exitCode = $this->commandTester->execute([]);

        // ALORS : La commande doit s'arrêter proprement et retourner Command::SUCCESS (0)
        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function testExecuteFailureWhenDatabaseCreationFailed(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvFile();
        $devInfos = new DatabaseInfosDto('dev_db', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('app_test', 'postgresql', false, true, false);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('Erreur de création');

        $this->resolver->method('getDatabaseInfosFromKernel')
            ->willReturnCallback(function (string $kernelClass, string $env) use ($devInfos, $testInfos) {
                return 'dev' === $env ? $devInfos : $testInfos;
            });

        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);
        $this->resolver->method('handleDoctrineCommands')->willReturn($process);

        // QUAND
        $exitCode = $this->commandTester->execute([], ['inputs' => ['yes']]);

        // ALORS
        $this->assertSame(Command::FAILURE, $exitCode);
    }

    #[Test]
    public function testExecuteFailureWhenSynchronisationFailed(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvFile();
        $devInfos = new DatabaseInfosDto('dev_db', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('app_test', 'postgresql', false, true, false);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $this->resolver->method('getDatabaseInfosFromKernel')
            ->willReturnCallback(function (string $kernelClass, string $env) use ($devInfos, $testInfos) {
                return 'dev' === $env ? $devInfos : $testInfos;
            });

        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);
        $this->resolver->method('handleDoctrineCommands')->willReturn($process);
        // handleDoctrineSynchronisation retourne Command::FAILURE (1) qui évalue à falsy en PHP si inversé
        $this->resolver->method('handleDoctrineSynchronisation')->willReturn(Command::FAILURE);

        // QUAND
        $exitCode = $this->commandTester->execute([], ['inputs' => ['yes']]);

        // ALORS
        $this->assertSame(Command::FAILURE, $exitCode);
    }

    #[Test]
    public function testExecuteNominalFullSuccess(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvFile();
        $devInfos = new DatabaseInfosDto('dev_db', 'postgresql', true, true, false);
        $testInfos = new DatabaseInfosDto('app_test', 'postgresql', false, true, false);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $this->resolver->method('getDatabaseInfosFromKernel')
            ->willReturnCallback(function (string $kernelClass, string $env) use ($devInfos, $testInfos) {
                return 'dev' === $env ? $devInfos : $testInfos;
            });

        $this->resolver->method('getDatabaseInfosWithTestEnv')->willReturn($testInfos);
        $this->resolver->method('handleDoctrineCommands')->willReturn($process);
        $this->resolver->method('handleDoctrineSynchronisation')->willReturn(Command::SUCCESS);

        // QUAND
        $exitCode = $this->commandTester->execute([], ['inputs' => ['yes']]);

        // ALORS
        $this->assertSame(Command::SUCCESS, $exitCode);
    }
}