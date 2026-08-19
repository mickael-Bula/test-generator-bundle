<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Service\TestGenerator;
use Mika\TestGeneratorBundle\Llm\LlmClientInterface;
use Mika\TestGeneratorBundle\Service\PhpUnitTestRunner;
use Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator;
use Mika\TestGeneratorBundle\Validator\SyntaxValidationResult;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;

#[CoversClass(TestGenerator::class)]
class TestGeneratorTest extends TestCase
{
    private MockObject&LlmClientFactory $llmFactory;
    private MockObject&PhpUnitTestRunner $testRunner;
    private MockObject&PhpSyntaxValidator $syntaxValidator;
    private array $promptBuilders;
    private TestGenerator $generator;

    protected function setUp(): void
    {
        $this->llmFactory = $this->createMock(LlmClientFactory::class);
        $this->testRunner = $this->createMock(PhpUnitTestRunner::class);
        $this->syntaxValidator = $this->createMock(PhpSyntaxValidator::class);
        $this->promptBuilders = [$this->createMock(TestPromptBuilderInterface::class)];

        $tempDir = sys_get_temp_dir();
        
        $this->generator = new TestGenerator(
            $this->llmFactory,
            $this->testRunner,
            $this->syntaxValidator,
            $this->promptBuilders,
            new Filesystem(),
            $tempDir,
        );
    }

    #[Test]
    public function testReturnsBuilderWhenTypeIsSupported(): void
    {
        // ÉTANT DONNÉ
        $this->promptBuilders[0]->method('supports')->with('unit')->willReturn(true);

        // QUAND
        $reflection = new \ReflectionClass(TestGenerator::class);
        $method = $reflection->getMethod('getPromptBuilder');
        $method->setAccessible(true);
        $result = $method->invoke($this->generator, 'unit');

        // ALORS
        $this->assertSame($this->promptBuilders[0], $result);
    }

    #[Test]
    public function testThrowsExceptionWhenNoBuilderSupportsType(): void
    {
        // ÉTANT DONNÉ
        $this->promptBuilders[0]->method('supports')->willReturn(false);

        // ALORS
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Aucun prompt builder trouvé pour le type "unknown".');

        // QUAND
        $reflection = new \ReflectionClass(TestGenerator::class);
        $method = $reflection->getMethod('getPromptBuilder');
        $method->setAccessible(true);
        $method->invoke($this->generator, 'unknown');
    }

    #[Test]
    public function testGenerateForClassThrowsExceptionOnMaxAttempts(): void
    {
        // ÉTANT DONNÉ
        $client = $this->createMock(LlmClientInterface::class);
        $this->llmFactory->method('getClient')->willReturn($client);
        $this->llmFactory->method('getDefaultModel')->willReturn('gpt-4');
        $this->promptBuilders[0]->method('supports')->willReturn(true);
        $this->promptBuilders[0]->method('buildPrompt')->willReturn(['system' => '', 'user' => '']);
        $client->method('call')->willReturn('class Test {}');
        $this->syntaxValidator->method('validate')->willReturn(SyntaxValidationResult::success());
        $this->testRunner->method('runTest')->willReturn(['success' => false, 'output' => 'fail']);

        // ALORS
        $this->expectException(TestCorrectionException::class);
        $this->expectExceptionMessage('Impossible de générer un test valide pour Foo après 3 tentatives.');

        // QUAND
        $this->generator->generateForClass('class Foo {}', 'src/Foo.php', 'App\Foo', 'Foo');
    }

    #[Test]
    public function testReplaceDynamicHeadersInExistingTestCode(): void
    {
        // ÉTANT DONNÉ
        $existing = 'namespace App\Tests; class FooTest {}';

        // QUAND
        $result = $this->generator->replaceDynamicHeadersInExistingTestCode($existing, 'App\Tests', 'Foo');

        // ALORS
        $this->assertSame('namespace App\Tests\Dynamic; class FooDynamicTest {}', $result);
    }

    #[Test]
    public function testGenerateForClassSavesFailedTestOnMaxAttempts(): void
    {
        // ÉTANT DONNÉ
        $client = $this->createMock(LlmClientInterface::class);
        $this->llmFactory->method('getClient')->willReturn($client);
        $this->llmFactory->method('getDefaultModel')->willReturn('gpt-4');

        // Configurer le builder pour qu'il gère les types 'unit' et 'fixer'
        $this->promptBuilders[0]->method('supports')->willReturn(true);
        $this->promptBuilders[0]->method('buildPrompt')->willReturn(['system' => '', 'user' => '']);

        $client->method('call')->willReturn('class FooTest {}');
        $this->syntaxValidator->method('validate')->willReturn(SyntaxValidationResult::success());
        $this->testRunner->method('runTest')->willReturn(['success' => false, 'output' => 'PHPUnit error']);

        // ALORS
        $this->expectException(TestCorrectionException::class);
        $this->expectExceptionMessageMatches('/Impossible de générer un test valide.*conservé dans/s');

        // QUAND
        $this->generator->generateForClass('class Foo {}', 'src/Foo.php', 'App\Foo', 'Foo');
    }
}