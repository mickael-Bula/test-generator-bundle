<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Reflection\ThrownMessages;
use Mika\TestGeneratorBundle\Spec\SpecExpectationChecker;
use Mika\TestGeneratorBundle\Spec\SpecSanitizer;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture\MutableConsumer;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpecSanitizer::class)]
final class SpecSanitizerEntityTest extends TestCase
{
    private const SPEC = <<<'MD'
# Spécification des Tests : `Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture`

---

## Méthode `consume()`

### Cas de tests

#### `testConsume`
* **Type** : `NORMAL`
* **Entrées** : `$mutable` = `{"label":12,"id":1}`
MD;

    #[Test]
    public function unsettablePropertiesAreRemovedAndScalarsAligned(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        // ÉTANT DONNÉ
        $sanitizer = new SpecSanitizer($parser, new SpecExpectationChecker(new ThrownMessages($parser)));

        // QUAND
        $result = $sanitizer->sanitize(self::SPEC, MutableConsumer::class);

        // ALORS
        self::assertStringContainsString('`$mutable` = `{"label":"12"}`', $result);
        self::assertStringContainsString('Propriétés retirées', $result);
        self::assertStringContainsString('`$mutable` : id', $result);
    }
}
