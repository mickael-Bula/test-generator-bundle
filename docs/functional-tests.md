# Tests fonctionnels générés depuis un scénario Markdown

`llm:generate:functional:test` transforme un scénario écrit à la main (clics, saisies, attentes) en test PHPUnit
qui pilote un vrai navigateur (Playwright). Le code du test est produit par un gabarit déterministe : le LLM n'est
sollicité que pour désigner un élément de page quand les règles hésitent.

## Principe

1. Le scénario est lu (aucun LLM) : `Mika\TestGeneratorBundle\Functional\Parser`.
2. Une **sonde** rejoue le scénario dans le navigateur, dans un processus PHPUnit de l'hôte. À chaque étape elle
   scanne le DOM, retrouve l'élément visé par des règles (libellé, texte voisin, titre du panneau qu'un bouton ouvre,
   placeholder, aria-label…), exécute l'action et observe l'effet (panneau ouvert ou fermé, navigation).
3. Si une étape est **ambiguë** (plusieurs éléments à égalité) ou **introuvable**, la sonde s'arrête et rapporte
   les candidats. Le LLM en choisit un (ou aucun), puis la sonde est **relancée avec ce choix** : elle l'exécute,
   donc le valide. Chaque relance repart d'une base de test restaurée (DAMA).
4. Le gabarit écrit le test à partir du résultat de la sonde, sans LLM.
5. Le test est écrit dans la **zone d'attente** (`tests/Dynamic/Functional/`), puis **PHPUnit est lancé dessus**.
   Vert : le test est déplacé dans `tests/Unit/`. Rouge : il est mis de côté dans `var/failed_tests/functional/`,
   avec le journal PHPUnit. La zone d'attente ne conserve donc jamais un test dont le résultat est connu.

Un libellé mal orthographié n'est jamais « deviné » en silence : il devient un candidat, au mieux, et la commande
le dit dans son compte rendu (`[règle]`, `[LLM]`, `[navigation]`).

## Installation

### Dans le bundle

Les paquets Playwright ne sont nécessaires que pour exécuter les tests générés et la sonde :

```json
"suggest": {
    "playwright-php/playwright-symfony": "Exécution des tests fonctionnels générés et de la sonde"
}
```

À l'hôte (webtrader_assetmapper, inc6…) :

```bash
composer require --dev playwright-php/playwright-symfony
```

Les classes `Functional/Support` et `Functional/Runtime` manipulent les objets Playwright par des paramètres typés
`object` : elles ne dépendent pas des paquets Playwright. Leur comportement avec votre configuration PHPStan n'a pas
été vérifié.

### Câblage des services (`services.php` du bundle)

La resource qui charge `src/` doit **exclure** les classes qui ne sont pas des services :

```php
'Functional/Dto',
'Functional/Enum',
'Functional/Exception',
'Functional/Runtime',
'Functional/Support',
'Functional/Render/RenderOptions.php',
'Functional/Choice/LlmStepChooser.php',
```

Il faut un alias explicite pour le choix (deux implémentations existent) :

```php
use Mika\TestGeneratorBundle\Functional\Choice\LlmClientStepChooser;
use Mika\TestGeneratorBundle\Functional\Choice\StepChooserInterface;

$services->alias(StepChooserInterface::class, LlmClientStepChooser::class);
```

`ProbeRunnerInterface` et `GeneratedTestRunnerInterface` n'ont chacune qu'une implémentation
(`PhpUnitProbeRunner`, `PhpUnitGeneratedTestRunner`) : les alias sont automatiques. `PhpUnitProbeRunner` reçoit `%kernel.project_dir%` par attribut `#[Autowire]` ; ses autres paramètres ont des
valeurs par défaut (`tests/E2E/MikaScenarioProbe.php`, `vendor/bin/phpunit`, délai de 120 s).
`PhpUnitGeneratedTestRunner` fonctionne de même (`vendor/bin/phpunit`, délai de 300 s).

### Dans l'hôte

**1. La classe de base E2E** utilise les aides du bundle et sait connecter chaque profil nommé dans les scénarios
(`Authentification : mot de passe, profil « connecté »`) :

```php
abstract class E2ETest extends PlaywrightTestCase
{
    use FunctionalTestHelpersTrait;

    protected function loginAs(string $profile): void
    {
        // Le profil « default » est utilisé quand le scénario n'en nomme pas.
        [$email, $password] = match ($profile) {
            'default', 'simple' => ['e2e@exemple.fr', 'MotDePasseE2E123!'],
            'connecté' => ['e2e_connected_user@exemple.fr', 'MotDePasseE2E456!'],
            default => throw new \InvalidArgumentException(sprintf('Profil de connexion inconnu : « %s ».', $profile)),
        };

        // Projet WEBSSO : remplacer ce bloc par la stratégie éprouvée (jeton valide en session).
        $page = $this->open('/login');
        $page->locator('input[name="email"]')->fill($email);
        $page->locator('input[name="password"]')->fill($password);
        $page->locator('button[type="submit"]')->click();

        // Sans cette attente, la navigation suivante peut interrompre la soumission ; et des identifiants
        // refusés ressortent ici (la page reste sur /login), pas plus loin dans le scénario.
        $this->waitUntilUrlLeaves($page, '/login');
    }
}
```

Un profil inconnu doit **lever une exception** : une `loginAs()` qui ne fait rien lance le scénario sans être
connecté, et les étapes échouent alors sur la page de connexion.

Le trait fournit `open()`, `countRows()` (via la connexion du test, donc dans la transaction DAMA), les attentes
`waitUntil*` (par scrutation d'un résultat précis, jamais « réseau inactif »), `selectValue()` et `setChecked()`.

Le trait reste dans le bundle : les classes Playwright n'y apparaissent que dans des commentaires de type
(`@param \Playwright\Page\PageInterface`), jamais en type PHP, donc rien n'est chargé sans les paquets Playwright.
Ces commentaires servent l'analyse statique de l'hôte (`$page->locator()` dans les tests générés). Si PHPStan analyse
le bundle seul, il faut installer les paquets en développement (`composer require --dev playwright-php/playwright`) ou
ignorer l'erreur de type de commentaire inconnu pour ce fichier.

**2. La classe de sonde**, dans un fichier qui ne finit pas par `Test.php` (la suite normale l'ignore) :

```php
// tests/E2E/MikaScenarioProbe.php
final class MikaScenarioProbe extends E2ETest
{
    use ScenarioProbeTrait;

    public function testProbe(): void
    {
        $this->runScenarioProbe();
    }
}
```

La commande la lance avec `APP_ENV=test PLAYWRIGHT_E2E=1 XDEBUG_MODE=off`. Pour la lancer à la main (diagnostic) :

```bash
MIKA_PROBE_SCENARIO=tests/Scenarios/creation.md MIKA_PROBE_OUTPUT=/tmp/probe.json \
APP_ENV=test PLAYWRIGHT_E2E=1 XDEBUG_MODE=off \
vendor/bin/phpunit --filter testProbe tests/E2E/MikaScenarioProbe.php
cat /tmp/probe.json
```

## Format du scénario

```markdown
# Créer une position en attente

## Contexte
- Authentification : mot de passe, profil « trader »
- Page de départ : /

## Étapes
1. Cliquer sur le bouton « Nouvelle position en attente »
2. Saisir « 7200.00 » dans « Prix Achat CAC »
3. Sélectionner « Achat » dans la liste « Sens »
4. Cocher la case « Rendre le point d'entrée Actif »
5. Cliquer sur le bouton « Enregistrer la position »

## Attentes
- Le panneau « Nouvelle Position en attente » est fermé
- La table position contient 1 ligne de plus
```

| Étapes | Attentes |
|---|---|
| `Aller sur /chemin` · `Cliquer sur [le bouton\|lien\|onglet\|icône] « X »` · `Saisir « v » dans [le champ] « X »` · `Remplir « X » avec « v »` · `Sélectionner « v » dans [la liste] « X »` · `Cocher` / `Décocher « X »` | `L'URL est /chemin` (une URL complète est ramenée à son chemin) · `L'URL contient …` · `Le texte « X » est affiché` / `n'est plus affiché` · `Le panneau « X » est ouvert` / `fermé` · `La table T contient N ligne(s) de plus` / `de moins` / `N lignes` |

Les attentes de texte sont vérifiées par le test généré ; la sonde, elle, ne lit que les 3 000 premiers caractères
de la page et ne fait donc qu'avertir si elle ne retrouve pas le texte.

## Utilisation

```bash
bin/console llm:generate:functional:test tests/Scenarios/creation.md
bin/console llm:generate:functional:test tests/Scenarios/creation.md --dry-run      # affiche le test, n'écrit rien
bin/console llm:generate:functional:test tests/Scenarios/creation.md --no-llm       # n'appelle jamais le LLM
bin/console llm:generate:functional:test tests/Scenarios/creation.md --no-run       # écrit dans la zone d'attente, sans lancer PHPUnit
```

| Option | Rôle (défaut) |
|---|---|
| `--output`, `-o` | fichier `.php` ou dossier où le test **reste** (PHPUnit est lancé mais rien n'est déplacé) ; par défaut, la zone d'attente déduite du namespace (`App\Tests\…` → `tests/…`) |
| `--namespace` | `App\Tests\Dynamic\Functional` |
| `--base-class` | `App\Tests\E2E\E2ETest` |
| `--class` | dérivé du titre : `CreerUnePositionEnAttenteDynamicTest` |
| `--max-decisions` | 5 étapes tranchées par le LLM au plus, puis abandon |
| `--no-llm` | s'arrête sur une étape ambiguë et liste les candidats |
| `--dry-run` / `--force` | afficher sans écrire ni exécuter / écraser un fichier existant |
| `--no-run` | ne pas lancer PHPUnit : le test reste dans la zone d'attente |
| `--green-dir` | dossier des tests verts : `tests/Functional` |
| `--failed-dir` | dossier des tests en échec : `var/failed_tests/functional` |

Codes de sortie : `0` test généré **et vert** (ou affiché, ou écrit avec `--no-run`) · `1` échec (scénario
illisible, sonde en erreur, attente non constatée, fichier existant, PHPUnit rouge ou impossible à lancer…) · `2`
une étape reste à trancher (aucun test écrit).

### Ce qui se passe après l'écriture

- **Vert** = code de sortie 0 *et* bilan `OK (N tests, M assertions)`. Un test ignoré, risqué ou accompagné
  d'avertissements (`OK, but some tests were skipped!`) n'a rien vérifié : il est traité comme rouge.
- **Vert** : le test est déplacé dans `--green-dir`. Si ce dossier est sous `tests/`, le `namespace` du fichier est
  aligné sur lui (`tests/Functional` → `App\Tests\Functional`, avec l'import de la classe de base ajusté). Hors de `tests/`,
  le source est copié tel quel.
- **Rouge** (y compris délai de 300 s dépassé) : le test est déplacé dans `--failed-dir`, avec `<Classe>.log`
  (sortie complète de PHPUnit) à côté ; la fin de la sortie est affichée. Le namespace n'est pas modifié.
- **PHPUnit impossible à lancer** (binaire introuvable…) : le test n'est ni validé ni en échec, il reste dans la
  zone d'attente et le message l'indique.
- Un fichier de même nom déjà présent dans la zone d'attente ou dans `--green-dir` arrête la commande **avant**
  toute écriture, sauf `--force`. Dans `--failed-dir`, un test rouge précédent est remplacé.
- Avec `--output`, le test reste exactement où demandé, mais PHPUnit est lancé et le code de sortie en tient compte.

Exemple de compte rendu (étape ambiguë tranchée par le LLM) :

```
Scénario : Modifier l'objectif
Étapes résolues :
  1. Cliquer sur « Modifier l'objectif Core »  [LLM] (fragile)  body > main > button:nth-of-type(1)
Avertissement : Étape 1 : sélecteur fragile (chemin dans le DOM, il cassera si la page change) : body > main > button:nth-of-type(1)
Exécutions de la sonde : 2
Test écrit : /…/tests/Dynamic/Functional/ModifierLObjectifDynamicTest.php
Exécution de PHPUnit sur le test généré…
PHPUnit : vert. Test déplacé : /…/tests/Unit/ModifierLObjectifDynamicTest.php
```

Pour un scénario résolu par les règles seules, chaque étape porte `[règle]` et la sonde ne s'exécute qu'une fois.

## Ce que le LLM reçoit

Pour chaque étape tranchée : le titre du scénario, la liste des étapes, l'étape à résoudre et, pour au plus huit
candidats, leurs attributs (texte, label, texte voisin, aria-label, placeholder, title, titre du panneau ouvert,
id, name, href, sélecteur). Aucune valeur saisie, aucun contenu de page au-delà. Le client utilisé est celui du
`.env` (`LLM_PROVIDER`, `LLM_MODEL`) via `LlmClientInterface::call()`.

`call()` est conçu pour renvoyer du code de test et peut transformer la réponse (extraction JSON, `<?php`, blocs
Markdown). Le lecteur de réponse cherche donc `{"choice": N}` où qu'il soit dans le texte reçu, et une réponse
inexploitable est rapportée telle quelle (« Étape N : choix impossible : Réponse du LLM inexploitable… ») au lieu
d'être devinée.

## Dépannage

- **« Étape non résolue par les règles » alors que l'élément existe** : regarder la ligne « Page courante » du
  compte rendu. Sur `/login`, la connexion n'a pas eu lieu (profil non géré par `loginAs()`, identifiants refusés).
  Lancer avec `--no-llm` pour voir les candidats sans solliciter le LLM.
- **« Choix impossible » avec une erreur du client LLM** : l'erreur vient du fournisseur (Ollama, Gemini…), pas
  du scénario. Le compte rendu affiche alors quand même les candidats ; `--no-llm` permet de continuer sans lui.

## Limites connues

- **Sélecteurs fragiles** : sans `data-testid`, `id` ou `name`, la sonde retombe sur un chemin DOM
  (`body > main > button:nth-of-type(2)`). Le test fonctionne mais cassera si la page change ; la commande le
  signale. Ajouter un `aria-label` ou un `data-testid` sur ces éléments les fiabilise.
- Les relances de la sonde coûtent chacune une exécution de navigateur (quelques secondes).
- La sonde et le test généré s'exécutent contre la base de test restaurée par DAMA ; la commande ne vérifie pas
  elle-même la base (`db:resolve` n'est pas appelée).
- Une sonde interrompue au délai (120 s) est tuée ; un navigateur lancé par Playwright peut survivre
  quelques secondes.
- Pas de boucle de réparation PHPUnit/PHPStan sur les tests fonctionnels pour l'instant : un test rouge est mis de
  côté avec son journal, il n'est pas corrigé automatiquement.
- Le test généré est exécuté une fois. Un test instable (attente trop courte, donnée partagée) peut être jugé
  rouge puis vert à l'exécution suivante.
