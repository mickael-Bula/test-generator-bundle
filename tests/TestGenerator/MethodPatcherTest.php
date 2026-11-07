<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Quality\MethodPatcher;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodPatcher::class)]
final class MethodPatcherTest extends TestCase
{
    private const CLASS_CODE = <<<'PHP'
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
    public function aWindowsFileKeepsItsLineEndingsAndGivesTheSameExtracts(): void
    {
        // ÉTANT DONNÉ
        $windows = str_replace("\n", "\r\n", $this->source());

        // QUAND
        $method = $this->patcher->fromAnswer("public function testB(): void\n{\n    self::assertSame(1, \$this->value);\n}", 'testB');
        $patched = null === $method ? null : $this->patcher->replace($windows, 'testB', $method);

        // ALORS
        self::assertNotNull($patched);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $patched)); // aucun saut de ligne isolé : le fichier reste en CRLF
        self::assertSame(
            $this->patcher->extract($this->source(), 'testA'),
            $this->patcher->extract($windows, 'testA'),            // l'extrait est en LF dans les deux cas
        );
    }

    #[Test]
    public function testA(): void
    {
        self::assertSame(1, $this->value);
    }

    #[Test]
    public function testB(): void
    {
        self::assertSame(2, $this->value);
    }
}
PHP;

    private MethodPatcher $patcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patcher = new MethodPatcher((new ParserFactory())->createForNewestSupportedVersion());
    }

    /** Le code de test sans dépendre des fins de ligne du fichier qui contient ce test. */
    private function source(): string
    {
        return str_replace("\r\n", "\n", self::CLASS_CODE);
    }

    #[Test]
    public function bareMethodWithLostIndentationIsReindentedOnReplace(): void
    {
        // ÉTANT DONNÉ : cleanLlmOutput() retire l'indentation de la première ligne seulement
        $answer = "#[Test]\n    public function testB(): void\n    {\n        self::assertSame(1, \$this->value);\n    }";

        // QUAND
        $method = $this->patcher->fromAnswer($answer, 'testB');
        $patched = null === $method ? null : $this->patcher->replace($this->source(), 'testB', $method);

        // ALORS
        self::assertNotNull($patched);
        self::assertStringContainsString(
            "    #[Test]\n    public function testB(): void\n    {\n        self::assertSame(1, \$this->value);\n    }\n}",
            $patched,
        );
        self::assertSame($this->patcher->names($this->source()), $this->patcher->names($patched));
        self::assertSame(
            $this->patcher->extract($this->source(), 'testA'),
            $this->patcher->extract($patched, 'testA'),
        );
    }

    #[Test]
    public function methodIsExtractedFromAFullClassAnswer(): void
    {
        // QUAND
        $method = $this->patcher->fromAnswer($this->source(), 'testA');

        // ALORS
        self::assertSame("#[Test]\npublic function testA(): void\n{\n    self::assertSame(1, \$this->value);\n}", $method);
    }

    #[Test]
    public function phpOpeningTagBeforeABareMethodIsIgnored(): void
    {
        // QUAND
        $method = $this->patcher->fromAnswer("<?php\n\npublic function testA(): void\n{\n}", 'testA');

        // ALORS
        self::assertSame("public function testA(): void\n{\n}", $method);
    }

    #[Test]
    public function renamedMethodIsRejected(): void
    {
        // ALORS
        self::assertNull($this->patcher->fromAnswer("public function testZ(): void\n{\n}", 'testB'));
    }

    #[Test]
    public function replacementThatDoesNotParseIsRejected(): void
    {
        // ALORS
        self::assertNull($this->patcher->replace($this->source(), 'testB', 'public function testB(: void {'));
    }

    #[Test]
    public function environmentHoldsEverythingExceptTheTests(): void
    {
        // QUAND
        $environment = $this->patcher->environment($this->source());

        // ALORS
        self::assertStringContainsString('private int $value = 1;', $environment);
        self::assertStringContainsString('protected function setUp(): void', $environment);
        self::assertStringNotContainsString('testA', $environment);
        self::assertStringNotContainsString('testB', $environment);
    }

    #[Test]
    public function removedMethodDisappearsWithoutLeavingADoubleBlankLine(): void
    {
        // QUAND
        $pruned = $this->patcher->remove($this->source(), ['testA']);

        // ALORS
        self::assertNotContains('testA', $this->patcher->names($pruned));
        self::assertContains('testB', $this->patcher->names($pruned));
        self::assertStringNotContainsString("\n\n\n", $pruned);
    }
}
