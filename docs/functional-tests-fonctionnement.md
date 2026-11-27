# Tests fonctionnels générés : fonctionnement détaillé

Ce document explique **ce qui se passe, dans quel ordre et dans quel processus** quand on lance
`llm:generate:functional:test`. Pour l'installation et les options, voir `functional-tests.md`.

## 1. Réponse courte

- Le test n'est **pas écrit « à l'aveugle »** : le bundle ouvre réellement l'application dans un navigateur
  (Playwright), rejoue le scénario, **lit le DOM rendu** et calcule les sélecteurs à partir de ce qu'il y trouve.
- Le test produit est ensuite **du code statique** : les sélecteurs sont écrits en dur. Une fois généré, il ne
  scanne plus rien et n'appelle ni le LLM ni le générateur ; il pilote Playwright avec ces sélecteurs.
- Un sélecteur comme `button[data-bs-target="#offcanvasNewPositionwaiting"]` n'est **ni lu dans le Twig, ni
  deviné, ni demandé au LLM** dans le cas normal. Il est **construit par un script JavaScript exécuté dans la page**,
  à partir des attributs du bouton tel que le navigateur le voit (donc après rendu Twig, après Stimulus/Turbo).
  Voir le §4.
- Le **LLM n'intervient que si une étape est ambiguë ou introuvable** par les règles, et seulement pour choisir
  *un candidat parmi une liste* proposée par le scan. Dans le cas d'un scénario bien libellé, il n'est jamais appelé.
- **PHPUnit est lancé deux fois (au moins)** : pour la sonde (qui contient Playwright), puis pour vérifier le
  test généré.

## 2. Qui fait quoi, dans quel processus

Trois processus entrent en jeu. Le LLM n'est appelé que depuis le premier ; Playwright n'existe que dans les deux
processus PHPUnit.

| Processus | Lancé par | Contient | Appelle |
|---|---|---|---|
| **A. Commande** `bin/console llm:generate:functional:test` | vous | analyse du scénario, boucle de génération, rendu, déplacement du fichier | **LLM** (si besoin) ; lance B et C |
| **B. PHPUnit « sonde »** (`MikaScenarioProbe::testProbe`, classe générée ou fournie par l'hôte) | A, par `proc_open` (une fois par tour de boucle) | navigateur **Playwright**, scan du DOM, exécution du scénario | Playwright, base de test |
| **C. PHPUnit « test généré »** | A, une fois le fichier écrit | le test généré, navigateur **Playwright** | Playwright, base de test |

```
 A  commande console ─────────────────────────────────────────────────────────────►
    │ 1. parse le scénario (Markdown, sans LLM)
    │
    │      ┌──────────────── boucle de décisions (au plus --max-decisions) ───────────────┐
    │      │ 2. lance B ──► B  PHPUnit/Playwright : ouvre la page, boucle sur les étapes  │
    │      │                   (scan DOM → règles → action → rescan), puis les attentes   │
    │      │                ◄── écrit un JSON de résultat                                 │
    │      │ 3. lit le JSON : ok / échec / « décision requise à l'étape N »               │
    │      │ 4. si décision requise : appelle le LLM (1 appel), mémorise le choix, retour en 2
    │      └───────────────────────────────────────────────────────────────────────────────┘
    │ 5. rend le code du test (gabarit PHP, sans LLM)
    │ 6. écrit le fichier dans la zone d'attente (tests/Dynamic/Functional/)
    │ 7. lance C ──► C  PHPUnit/Playwright : exécute le test généré
    │ 8. vert → tests/Functional/    rouge → var/failed_tests/functional/ (+ .log)
```

Chaque exécution de B et de C ouvre son propre navigateur et repart d'une base de test restaurée (DAMA) : une
étape de la sonde qui crée une position ne laisse rien derrière elle.

## 3. Pas à pas

### Étape 0 : lecture du scénario (processus A, aucun LLM, aucun navigateur)

`FunctionalScenarioParser` lit le Markdown : titre, contexte (authentification, page de départ), étapes
(« Cliquer sur… », « Saisir … dans… », « Cocher… »), attentes. Un scénario mal formé échoue ici, avant toute
exécution.

### Étape 1 : lancement de la sonde (A → B)

`PhpUnitProbeRunner` prépare la classe de sonde, puis lance
`vendor/bin/phpunit --filter testProbe <classe de sonde>` :

- si l'hôte fournit `tests/E2E/MikaScenarioProbe.php`, c'est ce fichier qui est utilisé ;
- sinon le runner **écrit une classe `MikaScenarioProbe`** dans un dossier temporaire (`mika_probe_…`), qui étend la
  classe de base donnée par `--base-class` et utilise `ScenarioProbeTrait` ; le dossier est supprimé après
  l'exécution, qu'elle réussisse ou non.

Les paramètres passent par trois **chemins de fichiers** donnés dans des variables d'environnement, car PHPUnit
n'accepte pas d'argument personnalisé pour un test : `MIKA_PROBE_SCENARIO` (le scénario), `MIKA_PROBE_OUTPUT` (le
fichier temporaire où la sonde écrit son résultat JSON) et, aux tours suivants, `MIKA_PROBE_DECISIONS` (les choix
déjà faits, en JSON). Chaque exécution a ses propres fichiers temporaires, supprimés ensuite. Délai maximal : 120 s.

### Étape 2 : exécution du scénario dans le navigateur (processus B)

`ScenarioProbeTrait` appelle `loginAs($profil, $stratégie)` (classe de base de l'hôte) si le scénario déclare une
authentification, puis `ScenarioExecutor` :

1. **Ouvre la page de départ** (`open('/')`) et attend que la page se calme (`settle`).
2. **Compte les lignes** des tables citées par les attentes (`countRows`), avant toute action.
3. **Pour chaque étape du scénario**, dans l'ordre :
   1. **Scan du DOM** : un script JavaScript est exécuté *dans la page* (`Page::evaluate`). Il renvoie la liste
      des éléments interactifs visibles, avec libellé, texte voisin, placeholder, aria-label, etc., et un
      sélecteur CSS unique pour chacun (§4).
   2. **Résolution par règles** (`StepResolver`, PHP pur) : on compare le libellé de l'étape aux signaux de
      chaque élément, on note, on garde le meilleur (§5).
   3. **Si résolu** : l'action est **réellement exécutée** par Playwright (clic, saisie, case cochée, option
      choisie).
   4. **Attente du calme** de la page, puis **nouveau scan** : on note si un panneau s'est ouvert ou fermé, si
      l'URL a changé. C'est ce nouvel état qui sert à l'étape suivante.
   5. **Si ambigu ou introuvable** : la sonde **s'arrête** et écrit les candidats dans le JSON de résultat
      (statut « décision requise »). Rien n'est deviné.
4. **Vérification des attentes** (panneau ouvert ou fermé, URL, nombre de lignes). Une attente qui n'est pas
   encore vraie est réévaluée (nouveau scan) pendant 3 s avant d'être déclarée non constatée.

Le résultat est écrit en JSON : étapes résolues (sélecteur, conteneur, valeur d'option, effet observé), attentes,
avertissements.

### Étape 3 : lecture du résultat (processus A)

`FunctionalTestGenerator` lit le JSON :

| Statut de la sonde | Suite |
|---|---|
| `ok` | on passe au rendu (étape 5) |
| `failed` | la commande s'arrête avec le message (exit 1) |
| `needs_decision` | étape 4 |

### Étape 4 : décision de l'étape ambiguë (processus A, **seul endroit où le LLM est appelé**)

Seulement si la sonde a rapporté une étape non résolue :

- Avec `--no-llm` : la commande s'arrête, liste les candidats, exit 2.
- Sinon `LlmClientStepChooser` envoie **un message** au LLM du `.env` : titre du scénario, liste des étapes,
  étape à résoudre, et **au plus 8 candidats** numérotés avec leurs attributs. Aucune valeur saisie, rien de la
  page au-delà. Le LLM répond `{"choice": N}` (ou `null`).
- Le choix est mémorisé, puis **la sonde est relancée depuis le début** avec ce choix (retour à l'étape 1).
  Elle exécute le candidat choisi : **le choix est donc validé par l'exécution**, pas pris sur parole.

### Étape 5 : rendu du test (processus A, gabarit PHP, aucun LLM)

`FunctionalTestRenderer` transforme le résultat de la sonde en code : une classe `final`, une méthode
`test<Titre>`, des commentaires `// ÉTANT DONNÉ / QUAND / ALORS`, les sélecteurs **tels que la sonde les a
validés**, et des attentes à base de `waitUntil…` (le test attend ce qui doit arriver au lieu de supposer que
c'est instantané).

### Étapes 6 à 8 : écriture, vérification, rangement

Le fichier est écrit dans la zone d'attente, **PHPUnit est lancé dessus** (processus C, délai 300 s), puis :

- vert (code 0 **et** `OK (N tests, M assertions)`) : le test est déplacé dans `--green-dir`, namespace réaligné ;
- rouge : il est déplacé dans `--failed-dir` avec le journal `.log` de PHPUnit, et la commande sort avec le
  code 1.

## 4. D'où vient `button[data-bs-target="#offcanvasNewPositionwaiting"]`

**Ni du Twig, ni du LLM : du DOM rendu, lu par un script JavaScript à l'exécution de la sonde.**

Le bundle ne lit jamais vos fichiers `.twig`. Il ne voit que ce que le navigateur voit : le HTML après rendu
serveur, après exécution de Stimulus, de Turbo et de Bootstrap. Si le Twig génère
`data-bs-target="#offcanvasNewPositionwaiting"`, cette valeur arrive dans le DOM, et c'est dans le DOM qu'on la lit.
Si un contrôleur JavaScript ajoutait ou retirait un attribut, la sonde verrait le résultat final.

Pour chaque élément retenu, le script (`DomScanner::SCRIPT`) construit un sélecteur en essayant, **dans cet ordre**,
des candidats et en gardant **le premier qui désigne cet élément et seulement lui** (`querySelectorAll` renvoie
exactement un élément, et c'est le bon) :

1. `[data-testid="…"]`
2. `[data-test="…"]`
3. `#id` (ignoré si l'id contient `:` ou 5 chiffres ou plus : ids générés, instables)
4. `balise[name="…"]`, puis `balise[name="…"][value="…"]`
5. `balise[aria-label="…"]`
6. `balise[href="…"]`
7. `balise[data-bs-target="…"]`
8. `balise[type="submit"]`
9. `balise[title="…"]`
10. `balise[placeholder="…"]`

Si aucun candidat n'est unique, il se replie sur un **chemin dans le DOM** (`body > main > button:nth-of-type(2)`),
marqué **fragile** : le test fonctionne mais cassera si la page change, et la commande le signale.

Pour le bouton « Nouvelle position en attente », la commande a retenu `button[data-bs-target="#offcanvasNewPositionwaiting"]` : c'est
le premier candidat de la liste qui était présent **et** unique dans la page (les candidats 1 à 6 étaient absents ou non
uniques). Pour les champs du panneau, le sélecteur est calculé **à l'intérieur du panneau ouvert** :
`#position_buyPrice` (id), `#isActive` (id), `button[type="submit"]`. Le panneau lui-même est repéré par son id
(`#offcanvasNewPositionwaiting`) ; dans le test généré, on écrit
`$panel = $page->locator('#offcanvasNewPositionwaiting')` puis `$panel->locator('#position_buyPrice')`.

**Conséquence pratique** : les sélecteurs sont figés à la génération. Si vous renommez un `id` ou changez un
`data-bs-target` dans le Twig, le test généré casse ; on relance alors la commande pour le régénérer (avec
`--force`). Les attributs stables (`data-testid`, `id`, `name`) donnent les tests les plus durables.

## 5. Comment une étape est rattachée à un élément (règles)

`StepResolver` ne fait que des comparaisons de texte normalisé (accents, casse, espaces, icônes ignorés).

- **Éléments compatibles** : un « Cliquer » ne considère que liens et boutons ; « Saisir » que les champs texte ;
  « Cocher » que les cases et boutons radio ; « Sélectionner » que les listes.
- **Signaux de libellé**, par ordre de fiabilité (pour un clic) : `data-testid`, `aria-label`, texte du bouton,
  `label`, **titre du panneau qu'il ouvre** (lu via `data-bs-target`), `title`. Pour un champ : `label`, `aria-label`,
  **texte voisin** (texte situé juste avant dans le DOM), `placeholder`, `title`. Pour une case : `label`, texte qui
  suit la case, texte voisin.
- **Notation** : correspondance exacte ou « contient » selon la source, plus un petit bonus si le mot du scénario
  (« bouton », « lien », « onglet ») correspond au type de l'élément.
- **Décision** : l'élément est retenu si son score atteint 50 **et** s'il devance le suivant d'au moins 15 points.
  Sinon : **ambigu** (plusieurs candidats proches) ou **introuvable** (aucun, ou seulement des éléments désactivés).
  Dans les deux cas, on ne devine pas : la sonde s'arrête et propose les candidats.

## 6. Le LLM : quand, pour quoi, et ce qu'il ne fait pas

| Appelé | Jamais appelé |
|---|---|
| Une fois par étape ambiguë ou introuvable (au plus `--max-decisions`, 5 par défaut) | Pour lire le scénario |
| Pour choisir **un numéro** parmi les candidats du scan (8 au plus) | Pour écrire ou modifier le code du test |
| Depuis le processus A seulement | Pour construire un sélecteur |
| | Dans les processus PHPUnit (sonde et test généré) |
| | Avec `--no-llm` |

Le LLM ne reçoit pas le HTML de la page : seulement les attributs des candidats. Sa réponse est un entier ; un
numéro hors liste ou une réponse illisible est un échec rapporté tel quel, jamais corrigé en silence.

## 7. Les boucles

| Boucle | Où | Bornes | Rôle |
|---|---|---|---|
| **Décisions** | `FunctionalTestGenerator::generate()` | `--max-decisions` (5) ; arrêt si la même étape est redemandée ou sans candidat | relance la sonde **depuis le début** après chaque choix du LLM |
| **Étapes** | `ScenarioExecutor` | nombre d'étapes du scénario | scan → règles → action → calme → rescan, pour chaque étape |
| **Calme de la page** (`settle`) | sonde seulement | `networkidle`, puis DOM stable (deux relevés identiques, toutes les 100 ms), 3 s au plus | laisser finir requêtes et animations avant de scanner |
| **Attentes de la sonde** | `ScenarioExecutor::await()` | 3 s, relevé toutes les 100 ms | réévaluer une attente (panneau, URL, lignes) tant qu'elle n'est pas vraie |
| **Attentes du test généré** | `waitUntilVisible`, `waitUntilHidden`… | 5 s (`functionalTimeoutMs()`), toutes les 100 ms | ce que fait le test à chaque exécution |
| **Surveillance des processus** | `ProcessExecutor` | 120 s (sonde), 300 s (test généré) | lire la sortie du processus fils et le tuer au délai |

**Il n'y a pas de boucle de réparation** : un test rouge n'est pas corrigé ni regénéré automatiquement ; il est
mis de côté avec son journal. Il n'y a pas non plus de boucle PHPStan sur ces tests.

**Nombre d'ouvertures du navigateur** : pour un scénario entièrement résolu par les règles, 2 (une sonde, puis le
test généré). Avec *k* décisions du LLM : *k* + 2.

## 8. Ce qu'exécute vraiment le test généré

Le test généré ne contient plus de logique de recherche. Il fait, dans l'ordre :

```php
$this->loginAs('trader', 'password');          // classe de base de l'hôte : même appel que la sonde
$page = $this->open('/');                      // Playwright : ouvre la page
$positionRowsBefore = $this->countRows('position');

$page->locator('button[data-bs-target="#offcanvasNewPositionwaiting"]')->click();
$panel = $page->locator('#offcanvasNewPositionwaiting');
$this->waitUntilVisible($panel);               // attente active, 5 s au plus
$panel->locator('#position_buyPrice')->fill('8000.00');
// …
$panel->locator('button[type="submit"]')->click();
$this->waitUntilHidden($panel);

self::assertFalse($panel->isVisible());
self::assertSame($positionRowsBefore + 1, $this->countRows('position'));
```

- **Playwright** : à chaque `locator(...)`, `click()`, `fill()`, `isVisible()`.
- **PHPUnit** : le cadre (`E2ETest`), les assertions et la restauration de la base.
- **Ni LLM ni scanner** : si un sélecteur ne désigne plus rien, Playwright attend puis échoue avec un délai
  dépassé, et le test est rouge.

## 9. Où lire quand ça ne marche pas

| Symptôme | Cause probable | Où regarder |
|---|---|---|
| « Étape non résolue par les règles » | libellé absent ou ambigu | ligne « Page courante » et liste « Candidats » du compte rendu ; `--no-llm` pour ne pas solliciter le LLM |
| « Page courante : /login » | la connexion n'a pas eu lieu | `loginAs()` de l'hôte (profil du scénario) |
| « Attente … non constatée … après 3.0 s d'attente » | l'effet attendu n'arrive pas (formulaire refusé, par exemple) | exécuter la même saisie à la main ; la sonde exécute réellement l'action |
| « Choix impossible » | erreur du client LLM, ou réponse inexploitable | message du fournisseur ; les candidats restent affichés |
| Test rouge après génération verte de la sonde | différence entre sonde et test (attente, état partagé) | `var/failed_tests/functional/<Classe>.log` |
| Sélecteur « fragile » signalé | aucun attribut stable sur l'élément | ajouter `data-testid`, `id` ou `aria-label` dans le Twig |

## 10. Correspondance avec le code

| Étape | Classe |
|---|---|
| Lecture du scénario | `Functional\Parser\FunctionalScenarioParser` |
| Boucle de génération | `Functional\Generation\FunctionalTestGenerator` |
| Lancement de la sonde | `Functional\Probe\PhpUnitProbeRunner`, `Functional\Process\ProcessExecutor` |
| Côté hôte de la sonde | `Functional\Support\ScenarioProbeTrait`, dans la classe `MikaScenarioProbe` générée par `PhpUnitProbeRunner` (ou celle de l'hôte) |
| Exécution du scénario | `Functional\Runtime\ScenarioExecutor` |
| Pont Playwright | `Functional\Runtime\PlaywrightBrowser`, `PlaywrightActions` |
| Scan du DOM et sélecteurs | `Functional\Probe\DomScanner` (script JavaScript) |
| Règles de résolution | `Functional\Probe\StepResolver` |
| Choix par le LLM | `Functional\Choice\LlmClientStepChooser`, `ChoicePromptBuilder`, `ChoiceResponseParser` |
| Rendu du test | `Functional\Render\FunctionalTestRenderer` |
| Vérification et rangement | `Functional\Verification\PhpUnitGeneratedTestRunner`, `Functional\Generation\GeneratedTestDelivery` |
| Commande | `Command\GenerateFunctionalTestCommand` |
