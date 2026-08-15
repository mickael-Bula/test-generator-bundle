<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Command;

use Random\RandomException;
use Mika\TestGeneratorBundle\Command\GenerateTestCommand;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Resolver\SpecResolver;
use Mika\TestGeneratorBundle\Resolver\TestPathResolver;
use Mika\TestGeneratorBundle\Service\TestGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(GenerateTestCommand::class)]
final class GenerateTestCommandTest extends TestCase
{
    private string $tempDir;
    private Filesystem $filesystem;
    private TestGenerator&MockObject $testGenerator;
    private LlmClientFactory&MockObject $llmFactory;
    private ClassResolver&MockObject $classResolver;
    private SpecResolver&MockObject $specResolver;
    private TestPathResolver&MockObject $pathResolver;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/test_gen_cmd_' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);

        $this->testGenerator = $this->createMock(TestGenerator::class);
        $this->llmFactory = $this->createMock(LlmClientFactory::class);
        $this->classResolver = $this->createMock(ClassResolver::class);
        $this->specResolver = $this->createMock(SpecResolver::class);
        $this->pathResolver = $this->createMock(TestPathResolver::class);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->chmod($this->tempDir, 0777, 0000, true);
            $this->filesystem->remove($this->tempDir);
        }
    }

    private function createValidEnvironment(): void
    {
        $this->filesystem->mkdir($this->tempDir . '/vendor/bin');
        $this->filesystem->touch($this->tempDir . '/vendor/bin/phpunit');
        $this->filesystem->touch($this->tempDir . '/phpunit.xml');
    }

    #[Test]
    public function testExecuteFailsWhenPhpunitBinaryIsMissing(): void
    {
        // ÉTANT DONNÉ
        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString("PHPUnit n'est pas installé", $commandTester->getDisplay());
    }

    #[Test]
    public function testExecuteFailsWhenPhpunitConfigIsMissing(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->mkdir($this->tempDir . '/vendor/bin');
        $this->filesystem->touch($this->tempDir . '/vendor/bin/phpunit');

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Aucun fichier de configuration PHPUnit', $commandTester->getDisplay());
    }

    #[Test]
    public function testExecuteFailsWhenBothUnitAndFunctionalFlagsAreProvided(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        $this->filesystem->touch($srcFile);

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute([
            'class' => 'App\Service\Foo',
            '--unit' => true,
            '--functional' => true,
        ]);

        // ALORS
        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Vous ne pouvez pas spécifier à la fois --unit (-u) et --functional (-f)', $commandTester->getDisplay());
    }

    #[Test]
    public function testExecuteUsesUnitTypeByDefault(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->llmFactory->method('getDefaultModel')->willReturn('default-model');
        $this->llmFactory->method('getDefaultProvider')->willReturn('default-provider');
        $this->specResolver->method('resolve')->willReturn(null);
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $this->testGenerator->expects($this->once())
            ->method('generateForClass')
            ->with(
                '<?php class Foo {}',
                $srcFile,
                'App\Service\Foo',
                'Foo',
                'default-model',
                null,
                null,
                null,
                'unit',
                'default-provider'
            )
            ->willReturn('<?php class FooTest {}');

        $this->testGenerator->method('replaceDynamicHeadersInTestCode')->willReturn('<?php class FooTest {}');

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::SUCCESS, $statusCode);
    }

    #[Test]
    public function testExecuteFailsWhenClassOrFileDoesNotExist(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();

        $this->classResolver->method('resolve')
            ->willThrowException(new \InvalidArgumentException('Class not found'));

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\NonExistent']);

        // ALORS
        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Class not found', $commandTester->getDisplay());
    }

    #[Test]
    public function testExecuteWithModelSpecAndMethodOptions(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->specResolver->method('resolve')->willReturn('Spec details');
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $this->testGenerator->expects($this->once())
            ->method('generateForClass')
            ->with(
                '<?php class Foo {}',
                $srcFile,
                'App\Service\Foo',
                'Foo',
                'custom-model',
                'doSomething',
                null,
                'Spec details',
                'unit',
            )
            ->willReturn('<?php class FooTest {}');

        $this->testGenerator->method('replaceDynamicHeadersInTestCode')->willReturn('<?php class FooTest {}');

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute([
            'class' => 'App\Service\Foo',
            '--model' => 'custom-model',
            '--spec' => 'spec.md',
            '--method' => 'doSomething',
        ]);

        // ALORS
        $this->assertSame(Command::SUCCESS, $statusCode);
    }

    #[Test]
    public function testExecuteCreatesNewTestFileSuccessfully(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->llmFactory->method('getDefaultModel')->willReturn('default-model');
        $this->llmFactory->method('getDefaultProvider')->willReturn('default-provider');
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $this->testGenerator->method('generateForClass')->willReturn('<?php class RawTest {}');
        $this->testGenerator->method('replaceDynamicHeadersInTestCode')->willReturn('<?php class FooTest {}');

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertFileExists($testFile);
        $this->assertSame('<?php class FooTest {}', file_get_contents($testFile));
    }

    #[Test]
    public function testExecuteCancelsMergeWhenUserRefusesConfirmation(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';
        $this->filesystem->mkdir(dirname($testFile));
        file_put_contents($testFile, '<?php class ExistingTest {}');

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['no']);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('Génération annulée', $commandTester->getDisplay());
    }

    #[Test]
    public function testExecuteFailsWhenExistingFileIsDirtyAndUserRefuses(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        exec('git init ' . escapeshellarg($this->tempDir));

        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';
        $this->filesystem->mkdir(dirname($testFile));
        file_put_contents($testFile, '<?php class ExistingTest {}');

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['yes', 'no']);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::FAILURE, $statusCode);
    }

    #[Test]
    public function testExecuteMergesAndUpdatesExistingTestFileSuccessfully(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';
        $this->filesystem->mkdir(dirname($testFile));
        file_put_contents($testFile, '<?php class ExistingTest {}');

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->llmFactory->method('getDefaultModel')->willReturn('default-model');
        $this->llmFactory->method('getDefaultProvider')->willReturn('default-provider');
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $this->testGenerator->method('replaceDynamicHeadersInExistingTestCode')
            ->willReturn('<?php class PreparedExistingTest {}');
        $this->testGenerator->method('generateForClass')
            ->with(
                '<?php class Foo {}',
                $srcFile,
                'App\Service\Foo',
                'Foo',
                'default-model',
                null,
                '<?php class PreparedExistingTest {}',
                null,
                'unit',
                'default-provider'
            )
            ->willReturn('<?php class MergedTest {}');
        $this->testGenerator->method('replaceDynamicHeadersInTestCode')
            ->willReturn('<?php class FinalMergedTest {}');

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['yes']);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('<?php class FinalMergedTest {}', file_get_contents($testFile));
    }

    #[Test]
    public function testExecuteCatchesLlmGenerationException(): void
    {
        // ÉTANT DONNÉ
        $this->createValidEnvironment();
        $srcFile = $this->tempDir . '/src/Service/Foo.php';
        $this->filesystem->mkdir(dirname($srcFile));
        file_put_contents($srcFile, '<?php class Foo {}');

        $testFile = $this->tempDir . '/tests/Service/FooTest.php';

        $this->classResolver->method('resolve')->willReturn([
            'className' => 'App\Service\Foo',
            'filePath' => $srcFile,
        ]);
        $this->llmFactory->method('getDefaultModel')->willReturn('default-model');
        $this->llmFactory->method('getDefaultProvider')->willReturn('default-provider');
        $this->pathResolver->method('resolve')->willReturn([
            'App\Tests\Service',
            dirname($testFile),
            $testFile,
        ]);

        $this->testGenerator->method('generateForClass')
            ->willThrowException(new TestCorrectionException('LLM failed to generate valid code'));

        $command = new GenerateTestCommand(
            $this->testGenerator,
            $this->llmFactory,
            $this->tempDir,
            $this->classResolver,
            $this->specResolver,
            $this->pathResolver,
        );
        $commandTester = new CommandTester($command);

        // QUAND
        $statusCode = $commandTester->execute(['class' => 'App\Service\Foo']);

        // ALORS
        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('LLM failed to generate valid code', $commandTester->getDisplay());
    }
}