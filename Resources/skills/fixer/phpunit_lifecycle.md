# SKILL : INTERDICTION FORMELLE DES MÉTHODES DE CONFIGURATION CUSTOM (setUpDependencies)

Le rapport d'erreur indique une violation critique du cycle de vie PHPUnit 
(ex : `Call to a member function expects() on null` ou présence d'une méthode `setUpDependencies()`).

CONSIGNES DE CORRECTION IMPÉRATIVES :
1. **Suppression totale des méthodes custom d'initialisation** : Il est **formellement interdit** de créer ou d'appeler des méthodes comme `setUpDependencies()`.
2. **Utilisation exclusive et obligatoire de `setUp(): void`** :
   - Tous les mocks et l'instance de la classe testée (SUT) **doivent** être initialisés à l'intérieur de la méthode native `protected function setUp(): void`.
   - Les propriétés de la classe de test (ex: `$this->repoMock`, `$this->sut`) doivent y être affectées une bonne fois pour toutes afin d'être immédiatement 
     disponibles dans chaque méthode de test (`#[Test]`).
3. **Interdiction d'initialiser les mocks à la volée** : Ne place jamais d'appels à `expects()` sur un mock avant que celui-ci n'ait été initialisé dans le `setUp()`.