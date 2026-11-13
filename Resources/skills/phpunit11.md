# Règles PHPUnit 11

## 1. Méthodes supprimées
- N'utilise jamais `->withConsecutive()` ni `->at()` : ils n'existent plus depuis PHPUnit 10.

## 2. Plusieurs appels d'une même méthode avec des retours différents
Utilise `->willReturnCallback()` avec un compteur :

```php
$callCount = 0;
$repository->expects(self::exactly(2))
    ->method('findOneBy')
    ->willReturnCallback(function (array $criteria, ?array $orderBy = null) use (&$callCount, $first, $second) {
        ++$callCount;

        return 1 === $callCount ? $first : $second;
    });
```

## 3. Data Providers
- Quand la spec décrit un Data Provider, génère-le : méthode `public static`, référencée par `#[DataProvider('provideXxx')]` sur le test.
