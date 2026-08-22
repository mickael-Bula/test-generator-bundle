# SKILL: PHP Reflection API (PHP 8.1+)

## Contexte & Règle
Depuis PHP 8.1, l'API de Réflexion (`ReflectionMethod`, `ReflectionProperty`, `ReflectionClass`) 
permet d'invoquer des méthodes privées/protégées et d'accéder aux propriétés encapsulées SANS avoir besoin de changer leur visibilité.

## Consignes de correction :
1. **INTERDICTION D'UTILISER `setAccessible()`** : 
   Ne génère JAMAIS d'appel à `$method->setAccessible(true)` ou `$property->setAccessible(true)`. 
   Cette méthode est désormais sans effet et obsolète.
2. **Exécution directe** : Invoque directement la méthode privée via `$reflectionMethod->invoke($object, ...$args)` 
   ou accède à la propriété via `$reflectionProperty->getValue($object)`.

## Exemple de code conforme :
```php
// BONNE PRATIQUE (PHP 8.1+)
$reflection = new \ReflectionClass(MyService::class);
$method = $reflection->getMethod('privateMethod');
$result = $method->invoke($serviceInstance, 'arg1');
```

## Anti-pattern à corriger absolument :
```php
// À NE PAS FAIRE (Obsolète)
$reflection = new \ReflectionClass(MyService::class);
$method = $reflection->getMethod('privateMethod');
$method->setAccessible(true); // <-- À SUPPRIMER
$result = $method->invoke($serviceInstance, 'arg1');
```
