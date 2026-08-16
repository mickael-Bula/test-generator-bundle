<?php

declare(strict_types=1);

namespace Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use Mika\TestGeneratorBundle\Dto\ResolvedClass;
use Mika\TestGeneratorBundle\Dto\SpecTargetPath;
use Mika\TestGeneratorBundle\Manager\SpecManager;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Symfony\Component\Console\Tester\CommandTester;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Dto\SpecGenerationResult;
use Mika\TestGeneratorBundle\Command\GenerateSpecCommand;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;

#[CoversClass(GenerateSpecCommand::class)]
class GenerateSpecCommandTest extends TestCase
{
    private MockObject&LlmClientFactory $llmFactory;
    private MockObject&ClassResolver $classResolver;
    private MockObject&SpecManager $specManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->llmFactory = $this->createMock(LlmClientFactory::class);
        $this->classResolver = $this->createMock(ClassResolver::class);
        $this->specManager = $this->createMock(SpecManager::class);

        $command = new GenerateSpecCommand(
            $this->llmFactory,
            $this->classResolver,
            $this->specManager
        );

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function testExecuteReturnsFailureOnInvalidClass(): void
    {
        // ÉTANT DONNÉ
        $this->classResolver->method('resolve')->willThrowException(new \InvalidArgumentException('Class not found'));

        // ALORS
        $this->assertSame(Command::FAILURE, $this->commandTester->execute(['class' => 'InvalidClass']));
    }

    #[Test]
    public function testExecuteReturnsFailureOnBothUnitAndFunctionalFlags(): void
    {
        // ÉTANT DONNÉ
        $args = ['class' => 'SomeClass', '--unit' => true, '--functional' => true];

        // ALORS
        $this->assertSame(Command::FAILURE, $this->commandTester->execute($args));
    }

    #[Test]
    public function testExecuteReturnsFailureIfFileUnreadable(): void
    {
        // ÉTANT DONNÉ
        $resolved = new ResolvedClass('ValidClass', '/non/existent/file');
        $this->classResolver->method('resolve')->willReturn($resolved);

        // ALORS
        $this->assertSame(Command::FAILURE, $this->commandTester->execute(['class' => 'ValidClass']));
    }

    #[Test]
    public function testExecuteSuccessWithNominalFlow(): void
    {
        // ÉTANT DONNÉ
        $filePath = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($filePath, '<?php class ValidClass {}');
        $resolved = new ResolvedClass('ValidClass', $filePath);
        $result = new SpecGenerationResult(new SpecTargetPath('dir', 'file.md'), 'content', null);

        $this->classResolver->method('resolve')->willReturn($resolved);
        $this->llmFactory->method('getDefaultModel')->willReturn('gpt-4');
        $this->specManager->method('generateAndSaveSpec')->willReturn($result);

        // ALORS
        $this->assertSame(Command::SUCCESS, $this->commandTester->execute(['class' => 'ValidClass']));
        unlink($filePath);
    }

    #[Test]
    public function testExecuteHandlesTestGenerationException(): void
    {
        // ÉTANT DONNÉ
        $filePath = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($filePath, '<?php class ValidClass {}');
        $resolved = new ResolvedClass('ValidClass', $filePath);
        $this->classResolver->method('resolve')->willReturn($resolved);
        $this->specManager->method('generateAndSaveSpec')->willThrowException(new TestGenerationException('LLM Error'));

        // ALORS
        $this->assertSame(Command::FAILURE, $this->commandTester->execute(['class' => 'ValidClass']));
        unlink($filePath);
    }
}