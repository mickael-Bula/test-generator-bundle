<?php

declare(strict_types=1);

namespace Dto;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mika\TestGeneratorBundle\Dto\GeneratedTestResult;

#[CoversClass(GeneratedTestResult::class)]
final class GeneratedTestResultTest extends TestCase
{
    #[Test]
    public function testInstantiationAndDto(): void
    {
        // ÉTANT DONNÉ
        $code = '<?php echo "Hello";';
        $result = new GeneratedTestResult($code);

        // QUAND
        $actual = $result->testCode;

        // ALORS
        $this->assertSame($code, $actual);
    }

    #[Test]
    public function testGetCleanTestCodeNormalizesLineEndings(): void
    {
        // ÉTANT DONNÉ
        $input = "<?php\r\nclass Foo {}\r\n";
        $result = new GeneratedTestResult($input);

        // QUAND
        $cleanCode = $result->getCleanTestCode();

        // ALORS
        $this->assertSame("<?php\nclass Foo {}", $cleanCode);
    }

    #[Test]
    public function testGetCleanTestCodeRemovesMarkdownPhpTags(): void
    {
        // ÉTANT DONNÉ
        $input = "```php\n<?php\nclass Foo {}\n```";
        $result = new GeneratedTestResult($input);

        // QUAND
        $cleanCode = $result->getCleanTestCode();

        // ALORS
        $this->assertSame("<?php\nclass Foo {}", $cleanCode);
    }

    #[Test]
    public function testGetCleanTestCodeRemovesMarkdownGenericTags(): void
    {
        // ÉTANT DONNÉ
        $input = "```\n<?php\nclass Foo {}\n```";
        $result = new GeneratedTestResult($input);

        // QUAND
        $cleanCode = $result->getCleanTestCode();

        // ALORS
        $this->assertSame("<?php\nclass Foo {}", $cleanCode);
    }

    #[Test]
    public function testGetCleanTestCodeFixesEscapedApostrophes(): void
    {
        // ÉTANT DONNÉ
        $input = <<<'PHP'
$msg = 'L\'élément n\'est pas valide';
PHP;
        $result = new GeneratedTestResult($input);

        // QUAND
        $cleanCode = $result->getCleanTestCode();

        // ALORS
        $expected = <<<'PHP'
$msg = 'L\'élément n\'est pas valide';
PHP;
        $this->assertSame($expected, $cleanCode);
    }

    #[Test]
    public function testGetCleanTestCodePreservesCleanCode(): void
    {
        // ÉTANT DONNÉ
        $input = <<<'PHP'
$msg = "L'élément est valide";
PHP;
        $result = new GeneratedTestResult($input);

        // QUAND
        $cleanCode = $result->getCleanTestCode();

        // ALORS
        $this->assertSame($input, $cleanCode);
    }
}