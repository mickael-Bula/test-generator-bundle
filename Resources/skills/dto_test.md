# Directives pour les DTO et Value Objects

- INTERDICTION STRICTE D'UTILISER `setUp()` OU DES PROPRIÉTÉS DE CLASSE :
    - La méthode `setUp()` est STRICTEMENT INTERDITE dans les tests de DTO / Value Objects.
    - Ne déclare AUCUNE propriété de classe (ex: `private MyDto $dto`).
    - CHAQUE méthode de test doit créer sa propre instance locale du DTO sous la section `// ÉTANT DONNÉ`.

- EXEMPLE D'ACCÈS AUX PROPRIÉTÉS OU GETTERS :
  // ÉTANT DONNÉ
  $code = 'my custom code';
  $dto = new GeneratedTestResult($code);

  // QUAND
  $actual = $dto->testCode;

  // ALORS
  $this->assertSame($code, $actual);
