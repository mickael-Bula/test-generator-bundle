<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Reflection\ThrownMessages;
use Mika\TestGeneratorBundle\Spec\SpecExpectationChecker;
use Mika\TestGeneratorBundle\Spec\SpecSanitizer;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture\VerdictConsumer;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpecSanitizer::class)]
final class SpecSanitizerEnumTest extends TestCase
{
    private const SPEC = <<<'MD'
# Spécification des Tests : `VerdictConsumer`

---

## Méthode `consume()`

### Cas de tests

#### `testConsumeThrowsOnUnknownVerdict`
* **Type** : `ERROR`
* **Entrées** : `$verdict` = `ARCHIVE`
* **Exception attendue** : `InvalidArgumentException`

MD;

    /**
     * Scénario : ARCHIVE n’est pas un cas de Verdict, et comme le cas est de type ERROR,
     * normalizeEnumInputs() l’écarte et renvoie null.
     * Les assertions vérifient que la section de la méthode disparaît et que le titre de la spec reste.
     *
     * @throws \ReflectionException
     */
    #[Test]
    public function methodWhoseOnlyCaseUsesAnInventedEnumValueIsRemoved(): void
    {
        // ÉTANT DONNÉ
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $sanitizer = new SpecSanitizer($parser, new SpecExpectationChecker(new ThrownMessages($parser)));

        // QUAND
        $result = $sanitizer->sanitize(self::SPEC, VerdictConsumer::class);

        // ALORS
        self::assertStringContainsString('# Spécification des Tests', $result);
        self::assertStringNotContainsString('## Méthode `consume()`', $result);
        self::assertStringNotContainsString('testConsumeThrowsOnUnknownVerdict', $result);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function windowsLineEndingsGiveTheSameResultAsUnixOnes(): void
    {
        // ÉTANT DONNÉ
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $sanitizer = new SpecSanitizer($parser, new SpecExpectationChecker(new ThrownMessages($parser)));
        $windows = str_replace("\n", "\r\n", self::SPEC);

        // QUAND
        $result = $sanitizer->sanitize($windows, VerdictConsumer::class);

        // ALORS
        self::assertSame($sanitizer->sanitize(self::SPEC, VerdictConsumer::class), $result);
        self::assertStringContainsString('# Spécification des Tests', $result);
    }
}
