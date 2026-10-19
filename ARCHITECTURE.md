## Les prompts

Les prompts sont déclarés dans le dossier `PromptBuilder`.
Il existe des prompts spécifiques pour chaque type de tests et chaque outil (PHPUnit, PHPStan, Specs, test unitaire, fonctionnel).
Tous les prompts implémentent une même interface, ce qui permet leur appel à l'aide d'un resolver.

Une fois le prompt récupéré, il vient initialiser, compléter ou remplacer en partie le prompt soumis au LLM.

Chaque prompt peut être enrichi par des skills, activés à partir de recherches textuelles dans les sorties du LLM.
Il existe des skills pour PHPUnit, pour PHPStan, pour le parser, pour la syntaxe PHP ou encore pour le fixer.

Certains skills (ex : `unit_test_safety.md`) sont systématiquement ajoutés au prompt principal. Ils visent à fixer des règles générales.

## La factory de LLM

## Repo-Map

Elle est ajoutée dans le prompt depuis `PhpUnitFixerPromptBuilder` avec `use RepoMapSystemMessageTrait;`.

TODO : Voir s'il est utile de la fournir, le LLM semblant ne pas s'en servir. 
Dans ce cas, elle serait utilisée pour retrouver les signatures et les fournir en cas d'erreur rencontrée 
(ex : `extractConstructorTypeErrorHint`).

On pourrait même n'utiliser que la reflection PHP pour fournir les méthodes et classes nécessaires plutôt que tout le projet.
On fournirait ainsi la classe à tester (SUT) et les signatures des dépendances de la classe testée (avec les DocBlocks) et rien d'autre.

## Les Skills personnalisés

Il faut ajouter la possibilité de déterminer un chemin pour ajouter des Skills personnalisés
Ce chemin sera à injecter dans la classe des skills customs.

## Chargements des Skills et des Détecteurs

Les Skills se trouvent dans le dossier `Resources/skills` et `Resources/skills/fixer`.
Ils sont appelés dans `TestGenerator::resolveForClass`.
Appelés également depuis la construction du prompt PHPUnit : `PhpUnitFixerPromptBuilder::buildPrompt`.

Les détecteurs sont déclarés dans et appelés depuis `FixerSkillResolver::resolveFromErrorOutput`.
Appelés depuis `PhpUnitFixerPromptBuilder::buildSystemMessage`.

## Améliorations possibles

L'appel des détecteurs se fait lors de la construction du prompt : pour PHPUnit, dans PhpUnitFixerPromptBuilder
Est-il nécessaire de transmettre la Repo-Map dans ce prompt, sachant que l'on précise le type d'erreur et sa solution ?

## TODO

- Ajouter en contexte du premier appel au LLM les classes des dépendances du SUT
- Voir s'il faut mettre à jour la génération des Specs : ajouter dans celles-ci des indices sur les signatures ?
- Vérifier si les Skills peuvent ou non être transformés en intercepteurs 
  - les Skills sont des fichiers texte qui s'ajoutent au prompt
  - les interceptors sont des correcteurs déterministes 
- Voir de quelle manière agréger les tests de méthodes réussis pour une même classe : comment les réunir dans une seule classe avec un même constructeur ?

Si la V2 réussi à générer des tests sans erreurs, il faudra supprimer : 
- la commande GenerateTestCommand.php
- PhpUnitFixerPromptBuilder.php
- PhpUnitFixerPromptBuilder.php
- UnitTestPromptBuilder.php
- TestGenerator.php
- les fixer Skills ?
- FixerSkillResolver.php ?

## Modifications Claude

Remplacer la variable Ns par Namespace dans `services.php` et dans la classe `SeedExtractor`.
Voir à réactiver l'intercepteur EntityOrObjectTypeErrorInterceptor après avoir modifié la condition d'activation.
Idem pour `DtoConstructorInterceptor.php`.

Des directives ont été ajoutées au fil de l'eau dans UnitTestPromptBuilderV2 (et autres) : déplacer dans des skills conditionnels pour alléger ?

## Commande FixTestCommand

Toute modification d’un intercepteur ou d’un pré-correcteur oblige à relancer une génération complète. 
Ce cycle est lent, et ses résultats varient d’une génération à l’autre. 
Une commande qui part d’un fichier de test existant, éventuellement accompagné de son rapport d’erreur, 
permet donc de rejouer exactement le même cas jusqu’à ce que le correctif soit validé.

```bash
# Dernier échec d'une classe : rejoue sans LLM (pré-correcteurs + indices restants)
php bin/console llm:fix:test PositionManager --no-llm

# Analyse du .log voisin, sans rien exécuter (--report APRÈS l'argument)
php bin/console llm:fix:test PositionManager --report

# Test explicite et rapport explicite
php bin/console llm:fix:test var/failed_tests/2026-10-17_10-03-22_PositionManager_FailedTestV2.php --report=cas/rapport.log

# Correction complète par le LLM, sans écrire de fichier
php bin/console llm:fix:test PositionManager --model=qwen3.5 --dry-run
```

Le fichier d’entrée n’est jamais modifié. Le résultat est écrit dans `<fichier>.fixed.php`, ou dans `--output`, ou nulle part avec `--dry-run`.

Le cycle de travail sur un problème devient : copier la paire `.php` et `.log` dans un dossier de cas, 
écrire ou ajuster le correcteur, relancer `--report` ou `--no-llm` jusqu’à ce que le diff soit le bon, puis seulement tester avec le LLM.

### Points d’attention
- **`--report` dépend de `TestSnapshotStore`**. Sans lui, les intercepteurs qui relisent le test ne verront rien dans ce mode.
- **Deux exécutions ne doivent pas tourner en parallèle** : le runner écrit puis supprime `tests/Dynamic/{Classe}DynamicTest.php`.
- **Les tests existants sont convertis puis restaurés** : un `PositionManagerTest` dans `App\Tests\Unit\Service` passe temporairement en `App\Tests\Dynamic`, 
  puis reprend son namespace à l’écriture. Si les en-têtes ne se convertissent pas, la commande s’arrête avant d’exécuter quoi que ce soit.
- **`--no-llm` met le test en conditions réelles** : il exécute vraiment PHPUnit et PHPStan sur le projet hôte. Le seul effet persistant est le fichier `.fixed.php`.

### Suite possible

Les paires `.php` et `.log` conservées peuvent devenir des **fixtures de non-régression** du bundle. 
Un test PHPUnit paramétré parcourrait un dossier de cas, appliquerait la chaîne de pré-correcteurs sur le rapport et le code, 
et comparerait à un `expected.php`. Chaque problème récurrent corrigé laisserait ainsi un test qui empêche son retour.

## Commande CaptureFixtureCommand

### Principe
Un cas est un dossier, et un test paramétré le rejoue contre les vrais services : `PreFixerChain` et `ErrorInterceptorRegistry`.

```text
tests/Fixtures/FailedTests/<nom-du-cas>/
├── input.php      test au format App\Tests\Dynamic (celui de var/failed_tests)
├── report.log     sortie PHPUnit/PHPStan, chemins absolus remplacés par %PROJECT_DIR%
├── expected.php   code après UNE passe de la chaîne ; absent => la chaîne doit renvoyer null
├── hints.txt      optionnel : une sous-chaîne attendue par ligne dans les indices des intercepteurs
└── meta.json      classe testée, empreinte de son code, date de capture
```

Une seule passe est comparée. 
Dans la vraie boucle, PHPUnit est relancé entre deux passes, et le rapport est refait. 
Rejouer plusieurs passes sur un rapport périmé ne ferait rien de réaliste.

Permet de capturer les échecs du LLM pour alimenter des fixtures pour la commande `llm:fix:test`.

Dans le pipeline réel, c’est PHPUnit qui les enchaîne : runWithPreFix() exécute PHPUnit, applique un correctif, réexécute PHPUnit, et ainsi de suite. 
Chaque passe a donc son propre rapport. Pour simuler ce comportement sans appel du LLM :

```bash
php bin/console llm:fix:test maClasse --no-llm # Pour exécuter la boucle complète sans LLM
```
Pour obtenir un enregistrement de chaque passe dans son propre fichier (crée un dossier par étape (01, 02, …).

```bash
php bin/console llm:fixture:capture ma-classe--run-1 var/failed_tests/01_test_FailedTestV2.php --no-llm --chain # enregistre le diff 
```

```bash
php bin/console llm:fixture:capture ma-classe--run-1 var/failed_tests/01_test_FailedTestV2.php # enregistre le diff 
php bin/console llm:fixture:capture ma-classe--run-1 var/failed_tests/01_test_FailedTestV2.php --report='tests/Fixtures/FailedTests/ma-classe--run-1-methods/report.log' # substitue ce fichier au .log appairé
```

Le diff est affiché à la capture. 
Il n’y a pas de commande d’acceptation. `expected.php` est la sortie enregistrée de la chaîne au moment de la capture. 
L’accepter, c’est relire le diff puis le committer.
- Diff faux : corrigez le correcteur, puis recapturez avec `--force`.
- Correction souhaitée, mais non encore produite par un correcteur : écrivez `expected.php` à la main. 
  Le test devient rouge jusqu’à ce que vous implémentiez le correcteur, ce qui revient à faire du TDD sur les correcteurs.
- Modification de plusieurs cas d’un coup : `UPDATE_FIXTURES=1 vendor/bin/phpunit --filter FailedTestFixturesTest` réécrit tous les `expected.php`. 
  Relisez ensuite git diff, et pour un scénario en étapes, recapturez-le : sinon `stepsAreChained` échouera.

Pour l'obtenir : `diff -u tests/Fixtures/FailedTests/ma-classe--run-1/input.php tests/Fixtures/FailedTests/maClasse--run-1/expected.php`

Après avoir modifié un correcteur, il est possible d'afficher le diff sans écrire dans un fichier :

```bash
php bin/console llm:fix:test <test.php> --report=<log> --dry-run # affiche le diff sans rien écrire.
```

### Boucler jusqu'à la résolution complète

```bash
php bin/console llm:fixture:capture ma-classe--run-1 var/failed_tests/<test>_FailedTestV2.php --chain
```

La boucle s’arrête dans l’un de ces trois états :
- **Vert** : le scénario est résolu sans LLM.
- **Aucun correcteur ne s’applique** : la dernière étape est le prochain problème à traiter. C’est l’objet de votre méthode un problème à la fois.
- **Limite** `--max-steps` atteinte.

### hint

`hints.txt` contient une phrase par ligne. Chacune doit apparaître dans le texte que les intercepteurs enverraient au LLM pour ce rapport.

La raison : `expected.php` vérifie ce que les correcteurs font tout seuls, mais rien ne vérifie le texte destiné au LLM. 
Si un intercepteur cesse de reconnaître l’erreur, ou perd l’information utile, le LLM perd son guidage et aucun test n’échoue. 
Le fichier `hints.txt` verrouille ce comportement. Il ne vérifie pas que le LLM obéit.

La commande génère un brouillon avec les titres `### …` de chaque indice. 
Conservez des phrases stables. 
Évitez les numéros de ligne, les durées et tout ce qui varie d’un run à l’autre.

### Le cycle de travail

1. Capturez en `--chain`.
2. Lisez le rapport de la dernière étape.
3. Écrivez le correctif correspondant.
4. Recapturez avec `--force`. L’ancienne dernière étape reçoit alors son `expected.php`, et la boucle va plus loin.
5. Répétez jusqu’au vert.

La suite de tests vérifie ensuite chaque étape et la cohérence de l’enchaînement.

### Créer un cas test

Sous `tests/Fixtures` créer le dossier `literal-stub-return`.
Y ajouter les fichiers suivants :

```php
// input.php
<?php

declare(strict_types=1);

namespace App\Tests\Dynamic;

use PHPUnit\Framework\TestCase;

final class StubDynamicTest extends TestCase
{
    public function testStub(): void
    {
        $strategyManager = $this->createMock(\App\Service\StrategyManager::class);

        $strategyManager->expects(self::once())
            ->method('calculateBuyLimit')
            ->willReturn(85);
    }
}
```
```php
// expexted.php
<?php

declare(strict_types=1);

namespace App\Tests\Dynamic;

use PHPUnit\Framework\TestCase;

final class StubDynamicTest extends TestCase
{
    public function testStub(): void
    {
        $strategyManager = $this->createMock(\App\Service\StrategyManager::class);

        $strategyManager->expects(self::once())
            ->method('calculateBuyLimit')
            ->willReturn('85');
    }
}
```
```txt
// report.log
There was 1 error:

1) App\Tests\Dynamic\StubDynamicTest::testStub
PHPUnit\Framework\MockObject\IncompatibleReturnValueException: Method calculateBuyLimit may not return value of type int, its declared return type is "string"

%PROJECT_DIR%/tests/Dynamic/StubDynamicTest.php:17
```
Exécuter la commande :

```bash
XDEBUG_MODE=off php bin/console llm:fixture:capture literal-stub-return tests/Fixtures/literal-stub-return/input.php --report=tests/Fixtures/literal-stub-return/report.log

Intercepteurs enregistrés : 22
Indices LLM : aucun.
                                                                                                         
 [OK] Cas "literal-stub-return" créé.                                                                                   
                                                                                                                        
```
Pour rejouer le test, ajouter le flag `--force` pour écraser le résultat précédents.

Parcours attendu de la chaîne : 
- 01 retrait des privés, 
- 02 ré-activation des publics, 
- 03 setUp, 
- 04 littéral, 
- 05 stub sur objet réel, 
- puis plus aucun correcteur.

## Priorité des intercepteurs

Priorités : 
- Skipped 100, 
- PrivateReflection 95,
- Unskip 90, 
- SetUpStub 80, 
- Literal 60, 
- StubOnReal 50, 
- LateExpectation 45, 
- MockCount 42, 
- FloatPrecision 40,
- TrivialAssertion 30,
- MissingClass 10, 
- MockProperty 0.

## Procédure pour les diffs

# Un correctif d'une étape
```bash
diff -u tests/Fixtures/FailedTests/position-manager--run-1/06/input.php tests/Fixtures/FailedTests/position-manager--run-1/06/expected.php
```

# Tout le scénario, du test d'origine à l'état final (écrit par la commande révisée)
```bash
less tests/Fixtures/FailedTests/position-manager--run-1/final.diff
```

# Quel correcteur à chaque étape
```bash
grep '"fixer"' tests/Fixtures/FailedTests/position-manager--run-1/*/meta.json
```

# Étapes sans correcteur
```bash
for d in tests/Fixtures/FailedTests/position-manager--run-1/[0-9][0-9]; do [ -f "$d/expected.php" ] || echo "$d"; done
```

## Commande recensant les intecepteurs

```bash
XDEBUG_MODE=off php bin/console llm:interceptors:census
```

| Colonne | Valeur | Conclusion |
|---|---|---|
| muet | >0 | `supports()` dit oui, `intercept()` ne produit rien : à corriger en priorité (motif partagé). |
| erreur | >0 | L’intercepteur lève une exception : bug. |
| reconnaît | 0 sur tous les rapports | Aucune preuve : les rapports ne couvrant que les scénarios, ne supprimer que si l’erreur est devenue impossible |
| Paires fréquentes | élevé | Deux intercepteurs répondent aux mêmes erreurs : comparer leurs consignes et les fusionner. |

Pour chaque intercepteur conservé, ajouter un cas de fixture avec une ligne dans `hints.txt `: le recensement n’en dit pas plus que les rapports générés.
