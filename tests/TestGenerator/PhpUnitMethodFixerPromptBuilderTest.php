<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\PromptBuilder\PhpUnitMethodFixerPromptBuilder;
use Mika\TestGeneratorBundle\Quality\MethodPatcher;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpUnitMethodFixerPromptBuilder::class)]
final class PhpUnitMethodFixerPromptBuilderTest extends TestCase
{
    private const TEST_CLASS = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Tests\Dynamic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DemoDynamicTest extends TestCase
{
    private int $value = 1;

    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function testA(): void
    {
        self::assertSame(1, $this->value);
    }

    #[Test]
    public function testB(): void
    {
        self::assertSame(99, $this->value);
    }
}
PHP;

    private function builder(): PhpUnitMethodFixerPromptBuilder
    {
        return new PhpUnitMethodFixerPromptBuilder(
            new ErrorInterceptorRegistry([]),
            new MethodPatcher((new ParserFactory())->createForNewestSupportedVersion()),
        );
    }

    /** @return array{system: string, user: string} */
    private function prompts(?string $provider = null, ?string $test = 'testB'): array
    {
        return $this->builder()->buildPrompt(
            classCode: '<?php final class Demo { public function run(): int { return 1; } }',
            filePath: 'src/Demo.php',
            fqcn: 'App\Demo',
            className: 'Demo',
            methodName: $test,
            existingTestCode: self::TEST_CLASS,
            specContent: "There was 1 failure:\n\n1) App\\Tests\\Dynamic\\DemoDynamicTest::testB\nFailed asserting that 1 is identical to 99.",
            provider: $provider,
        );
    }

    #[Test]
    public function theUserMessageHoldsOnlyTheFailingTestItsEnvironmentAndItsReport(): void
    {
        // QUAND
        $user = $this->prompts()['user'];

        // ALORS
        self::assertStringContainsString('assertSame(99, $this->value);', $user);
        self::assertStringContainsString('Failed asserting that 1 is identical to 99.', $user);
        self::assertStringContainsString('private int $value = 1;', $user);
        self::assertStringContainsString('protected function setUp(): void', $user);
        self::assertStringContainsString('final class Demo', $user);
        self::assertStringNotContainsString('testA', $user);
    }

    #[Test]
    public function theSystemMessageAsksForTheMethodOnlyInTheFormOfTheProvider(): void
    {
        // QUAND
        $raw = $this->prompts('ollama')['system'];
        $json = $this->prompts('openai')['system'];

        // ALORS
        self::assertStringContainsString('commence directement par l\'attribut', $raw);
        self::assertStringNotContainsString('test_code', $raw);
        self::assertStringContainsString("clé 'test_code'", $json);
        foreach ([$raw, $json] as $system) {
            self::assertStringContainsString('UN SEUL TEST', $system);
            self::assertStringContainsString('instruction `use`', $system);
        }
    }

    #[Test]
    public function theBuilderOnlyHandlesItsOwnType(): void
    {
        // ALORS
        self::assertTrue($this->builder()->supports('method_fixer'));
        self::assertFalse($this->builder()->supports('fixer_v2'));
    }

    #[Test]
    public function theNameOfTheTestIsMandatory(): void
    {
        // ALORS
        $this->expectException(\InvalidArgumentException::class);

        // QUAND
        $this->prompts(test: null);
    }
}
