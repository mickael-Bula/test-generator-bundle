# Spécification de Test : GeneratedTestResult

## Informations Générales
- **Classe à tester** : `Mika\TestGeneratorBundle\Dto\GeneratedTestResult`
- **Classe de test générée** : `Mika\TestGeneratorBundle\Tests\Dto\GeneratedTestResultTest`
- **Namespace du test** : `Mika\TestGeneratorBundle\Tests\Dto`

---

## 1. Instanciation et DTO
- **Description** : Vérifier que l'objet est correctement instancié et que la propriété readonly `$testCode` est accessible.
- **Scénario** :
  - **Étant donné** un code PHP brut de test : `<?php echo "Hello";`
  - **Quand** la classe `GeneratedTestResult` est instanciée avec ce code
  - **Alors** la propriété `$testCode` doit contenir exactement la valeur fournie.

---

## 2. Méthode `getCleanTestCode()`

### Cas 2.1 : Normalisation des sauts de ligne (Windows \r\n vers Unix \n)
- **Description** : La méthode doit remplacer tous les sauts de ligne Windows `\r\n` et Mac `\r` par des sauts de ligne Unix `\n`.
- **Scénario** :
  - **Étant donné** un code contenant des sauts de ligne Windows `<?php\r\nclass Foo {}\r\n`
  - **Quand** on appelle `getCleanTestCode()`
  - **Alors** le résultat ne doit plus contenir de `\r` et doit afficher `<?php\nclass Foo {}\n`.

### Cas 2.2 : Suppression des balises Markdown de bloc de code (` ```php `)
- **Description** : Si le LLM a entouré le code de balises Markdown avec ou sans `php`, elles doivent être purgées.
- **Scénario A** (Bloc complet ` ```php ... ``` `) :
  - **Étant donné** le texte ```` ```php\n<?php\nclass Foo {}\n``` ````
  - **Quand** on appelle `getCleanTestCode()`
  - **Alors** le résultat renvoyé doit être `<?php\nclass Foo {}`.
- **Scénario B** (Balises Markdown génériques sans `php`) :
  - **Étant donné** le texte ```` ```\n<?php\nclass Foo {}\n``` ````
  - **Quand** on appelle `getCleanTestCode()`
  - **Alors** le résultat renvoyé doit être `<?php\nclass Foo {}`.

### Cas 2.3 : Correction des apostrophes mal échappées (`\'`)
- **Description** : Les apostrophes échappées par un antislash dans des chaînes délimitées par des simples quotes (`'n\'est'`) doivent être converties en guillemets doubles (`"n'est"`).
- **Scénario** :
  - **Étant donné** le code PHP d'entrée sous forme de bloc Nowdoc (pour éviter toute confusion de guillemets) :
    ```php
    $msg = 'L\'élément n\'est pas valide';
    ```
  - **Quand** on appelle `getCleanTestCode()`
  - **Alors** le résultat renvoyé doit être exactement :
    ```php
    $msg = "L'élément n'est pas valide";
    ```

### Cas 2.4 : Conservation du code sain sans apostrophe échappée
- **Description** : Si le code ne contient aucun motif `\'`, il ne doit pas subir de transformation inutile via le callback regex.
- **Scénario** :
  - **Étant donné** un code PHP valide `$msg = "L'élément est valide";` ou `$val = 'test';`
  - **Quand** on appelle `getCleanTestCode()`
  - **Alors** le code reste strictement inchangé.