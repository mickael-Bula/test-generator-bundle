# SKILL : PORTÉE DES VARIABLES ET SÉPARATION DES FIXTURES (TypeError / Call to a member function on null)

Le rapport d'erreur indique qu'une variable (comme un utilisateur ou un DTO) est `null` ou indéfinie dans une méthode de test, 
car elle a été déclarée localement dans le `setUp()` au lieu d'être instanciée dans le test lui-même.

CONSIGNES DE CORRECTION :
1. **Ce qui va dans le `setUp()` (Mocks et SUT uniquement)** :
    - Seuls les mocks de services/repositories (ex : `$this->repoMock = $this->createMock(...)`) 
      et l'instance de la classe testée (`$this->sut`) doivent être initialisés dans `setUp()` et stockés dans des propriétés de la classe (`protected $...`).
2. **Ce qui va dans les méthodes de test `#[Test]` (Données de domaine)** :
    - Toutes les entités métier (ex : `$user = new User()`) et tous les DTOs (ex : `$dto = new MonDto(...)`) 
      **doivent obligatoirement être instanciés localement à l'intérieur de chaque méthode de test** où ils sont utilisés.
    - Ne déclare jamais d'entités ou de DTOs dans le `setUp()`, car leurs états varient d'un test à l'autre.
3. **Indépendance des tests** : Chaque méthode de test doit créer ses propres objets pour garantir sa totale autonomie.