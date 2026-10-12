# Règles de compatibilité PHPUnit 11

## 1. Méthodes strictement interdites
- N'utilise jamais `->withConsecutive()`. Cette méthode a été supprimée dans PHPUnit 10 et n'existe plus dans PHPUnit 11.

## 2. Assertion sur des appels séquentiels
Pour mocker plusieurs appels successifs à une même méthode avec des arguments ou des retours différents, utilise `->willReturnCallback()`:

```php
$callCount = 0;
$repository->expects(self::exactly(2))
    ->method('findOneBy')
    ->willReturnCallback(function (array $criteria, array $orderBy) use (&$callCount, $result1, $result2) {
        $callCount++;
        if (1 === $callCount) {
            self::assertSame(['owner' => $user], $criteria);
            return $result1;
        }
        return $result2;
    });
```

## 3. Attributs PHPUnit
Utilise les attributs PHPUnit 11 à la place des annotations PHPDoc :

- `#[PHPUnit\Framework\Attributes\Test]`
- `#[PHPUnit\Framework\Attributes\CoversClass(TargetClass::class)]`
- `#[PHPUnit\Framework\Attributes\DataProvider('providerMethod')]`

