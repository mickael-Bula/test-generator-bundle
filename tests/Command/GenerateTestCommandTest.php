<?php

declare(strict_types=1);

namespace Command;

use Random\RandomException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Filesystem\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Mika\TestGeneratorBundle\Manager\SpecManager;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Service\TestGenerator;
use Symfony\Component\Console\Tester\CommandTester;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Resolver\TestPathResolver;
use Mika\TestGeneratorBundle\Command\GenerateTestCommand;

#[CoversClass(GenerateTestCommand::class)]
final class GenerateTestCommandTest extends TestCase
{
    private string $tempDir;
    private Filesystem $filesystem;
    private MockObject|TestGenerator $testGenerator;
    private MockObject|LlmClientFactory $llmFactory;
    private MockObject|ClassResolver $classResolver;
    private MockObject|TestPathResolver $pathResolver;
    private MockObject|SpecManager $specManager;
    private string $projectDir;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/test_' . bin2hex(random_bytes(8));
        $this->projectDir = $this->tempDir;
        $this->filesystem->mkdir($this->projectDir);
        
        $this->testGenerator = $this->createMock(TestGenerator::class);
        $this->llmFactory = $this->createMock(LlmClientFactory::class);
        $this->classResolver = $this->createMock(ClassResolver::class);
        $this->pathResolver = $this->createMock(TestPathResolver::class);
        $this->specManager = $this->createMock(SpecManager::class);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->chmod($this->tempDir, 0777, 0000, true);
            $this->filesystem->remove($this->tempDir);
        }
    }

    #[Test]
    public function testAbsenceOfPhpunitBinaryReturnsFailure(): void
    {
        // ÉTANT DONNÉ
        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->projectDir,
            $this->classResolver,
            $this->pathResolver,
            $this->specManager
        );
        $tester = new CommandTester($command);

        // QUAND
        $result = $tester->execute(['class' => 'AnyClass']);

        // ALORS
        $this->assertSame(Command::FAILURE, $result);
    }

    #[Test]
    public function testAbsenceOfPhpunitConfigReturnsFailure(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->mkdir($this->projectDir . '/vendor/bin');
        $this->filesystem->touch($this->projectDir . '/vendor/bin/phpunit');
        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->projectDir,
            $this->classResolver,
            $this->pathResolver,
            $this->specManager
        );
        $tester = new CommandTester($command);

        // QUAND
        $result = $tester->execute(['class' => 'AnyClass']);

        // ALORS
        $this->assertSame(Command::FAILURE, $result);
    }

    #[Test]
    public function testIncompatibilityOfUnitAndFunctionalFlagsReturnsFailure(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->mkdir($this->projectDir . '/vendor/bin');
        $this->filesystem->touch($this->projectDir . '/vendor/bin/phpunit');
        $this->filesystem->touch($this->projectDir . '/phpunit.xml');
        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->projectDir,
            $this->classResolver,
            $this->pathResolver,
            $this->specManager
        );
        $tester = new CommandTester($command);

        // QUAND
        $result = $tester->execute(['class' => 'AnyClass', '--unit' => true, '--functional' => true]);

        // ALORS
        $this->assertSame(Command::FAILURE, $result);
    }

    #[Test]
    public function testInvalidClassResolutionReturnsFailure(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->mkdir($this->projectDir . '/vendor/bin');
        $this->filesystem->touch($this->projectDir . '/vendor/bin/phpunit');
        $this->filesystem->touch($this->projectDir . '/phpunit.xml');
        $this->classResolver->method('resolve')->willThrowException(new \InvalidArgumentException());
        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->projectDir,
            $this->classResolver,
            $this->pathResolver,
            $this->specManager
        );
        $tester = new CommandTester($command);

        // QUAND
        $result = $tester->execute(['class' => 'Invalid']);

        // ALORS
        $this->assertSame(Command::FAILURE, $result);
    }
}