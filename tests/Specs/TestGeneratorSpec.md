# Spécification des Tests : `Mika\TestGeneratorBundle\Service\TestGenerator`

* **Type de test** : Unit
* **Généré le** : 2026-08-18 19:06:03

---

## Dépendances à mocker

- `Mika\TestGeneratorBundle\Llm\LlmClientFactory` (`$llmFactory`)
- `Mika\TestGeneratorBundle\Service\PhpUnitTestRunner` (`$testRunner`)
- `Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator` (`$syntaxValidator`)
- `Iterable` (`$promptBuilders`)

---

## Méthode `getPromptBuilder()`

### Cas de tests

#### `testReturnsBuilderWhenTypeIsSupported`
* **Type** : `NOMINAL`
* **Description** : Vérifie qu'un builder est retourné si le type est supporté
* **Entrées** : `$type` = `unit`
* **Retour attendu** : `Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface`

#### `testThrowsExceptionWhenNoBuilderSupportsType`
* **Type** : `ERROR`
* **Description** : Vérifie qu'une exception est levée si aucun builder ne supporte le type
* **Entrées** : `$type` = `unknown`
* **Exception attendue** : `InvalidArgumentException`
  - Message : *"Aucun prompt builder trouvé pour le type "unknown"."*

---

## Méthode `generateForClass()`

### Data Provider : `provideGenerationScenarios`
> Scénarios de réussite et d'échec de la boucle de génération

| Label / Description | `$syntaxValid` | `$testSuccess` | `$expectedAttempts` |
| :--- | :--- | :--- | :--- |
| **Success on first attempt** | `true` | `true` | `1` |
| **Syntax failure then success** | `false` | `true` | `2` |

### Cas de tests

#### `testGenerateForClassThrowsExceptionOnMaxAttempts`
* **Type** : `ERROR`
* **Description** : Vérifie que l'exception TestCorrectionException est levée après MAX_ATTEMPT
* **Entrées** : `$classCode` = `class Foo {}`, `$filePath` = `src/Foo.php`, `$fqcn` = `App\Foo`, `$className` = `Foo`
* **Exception attendue** : `Mika\TestGeneratorBundle\Exception\TestCorrectionException`
  - Message : *"Impossible de générer un test valide pour Foo après 3 tentatives."*

---

## Méthode `replaceDynamicHeadersInExistingTestCode()`

### Cas de tests

#### `testReplaceDynamicHeadersInExistingTestCode`
* **Type** : `NOMINAL`
* **Description** : Vérifie le remplacement correct des headers de namespace et classe
* **Entrées** : `$existingTestCode` = `namespace App\Tests; class FooTest {}`, `$targetNamespace` = `App\Tests`, `$className` = `Foo`
* **Retour attendu** : `namespace App\Tests\Dynamic; class FooDynamicTest {}`

---
