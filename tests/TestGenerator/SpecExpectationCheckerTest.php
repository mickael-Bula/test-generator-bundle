<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Reflection\ThrownMessages;
use Mika\TestGeneratorBundle\Spec\SpecExpectationChecker;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpecExpectationChecker::class)]
final class SpecExpectationCheckerTest extends TestCase
{
    private const QUANTITY = <<<'MD'
## Méthode `quantity()`

### Data Provider : `provideQuantityData`
> Jeux de données.

| Label / Description | `$capital` | `$price` | `$expectedResult` |
| :--- | :--- | :--- | :--- |
| **Quantité valide** | `1000` | `50.2` | `(int)round(1000 / 50.2)` |
| **Prix nul** | `1000` | `0` | `null` |

### Cas de tests

#### `testQuantityThrowsWhenPriceIsZero`
* **Type** : `ERROR`
* **Entrées** : `$capital` = `1000`, `$price` = `0`
* **Exception attendue** : `InvalidArgumentException`
  - Message : *"Le prix cible ne peut pas être égal à zéro."*

#### `testQuantityThrowsRuntime`
* **Type** : `ERROR`
* **Entrées** : `$capital` = `1000`, `$price` = `0`
* **Exception attendue** : `RuntimeException`
  - Message : *"Autre"*

#### `testQuantityReturnsNull`
* **Type** : `SUCCESS`
* **Entrées** : `$capital` = `0`, `$price` = `10`
* **Retour attendu** : `null`

MD;

    private const FAILING = <<<'MD'
## Méthode `failing()`

### Cas de tests

#### `testFailingThrowsLogic`
* **Type** : `ERROR`
* **Entrées** : `$id` = `5`
* **Exception attendue** : `LogicException`
  - Message : *"Impossible de calculer le gap : la position #<id> n'est rattachée à aucun utilisateur."*

MD;

    private const MAYBE = <<<'MD'
## Méthode `maybe()`

### Cas de tests

#### `testMaybeReturnsNull`
* **Type** : `SUCCESS`
* **Entrées** : `$flag` = `false`
* **Retour attendu** : `null`

MD;

    private function check(string $chunk, string $method): ?string
    {
        $checker = new SpecExpectationChecker(new ThrownMessages((new ParserFactory())->createForNewestSupportedVersion()));
        $sut = new \ReflectionClass(QuantityCalculator::class);

        return $checker->check($chunk, $sut, $sut->getMethod($method));
    }

    #[Test]
    public function impossibleNullAndInventedExceptionAreRemoved(): void
    {
        // QUAND
        $result = $this->check(self::QUANTITY, 'quantity');

        // ALORS
        self::assertNotNull($result);
        self::assertStringContainsString('**Quantité valide**', $result);
        self::assertStringNotContainsString('**Prix nul**', $result);                       // `null` pour un `int`
        self::assertStringNotContainsString('testQuantityReturnsNull', $result);            // idem, hors provider
        self::assertStringContainsString('testQuantityThrowsWhenPriceIsZero', $result);     // le `throw` existe
        self::assertStringNotContainsString('testQuantityThrowsRuntime', $result);          // aucun `throw new RuntimeException`
    }

    #[Test]
    public function placeholderInMessageIsReplacedByTheStablePart(): void
    {
        // QUAND
        $result = $this->check(self::FAILING, 'failing');

        // ALORS
        self::assertNotNull($result);
        self::assertStringContainsString('Message : *"Impossible de calculer le gap : la position #"*', $result);
        self::assertStringNotContainsString('<id>', $result);
    }

    #[Test]
    public function nullIsKeptWhenTheReturnTypeIsNullable(): void
    {
        // QUAND
        $result = $this->check(self::MAYBE, 'maybe');

        // ALORS
        self::assertNotNull($result);
        self::assertStringContainsString('testMaybeReturnsNull', $result);
    }
}
