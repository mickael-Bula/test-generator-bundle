# Test Generator Bundle pour Symfony

Le **Test Generator Bundle** intègre dans votre application Symfony un outil de génération automatique de tests unitaires
et fonctionnels PHPUnit piloté par un LLM (via Symfony AI Platform).

Il prend en compte le contexte global de votre projet (Repo-Map / AST),
supporte la rédaction de spécifications en Markdown (BDD)
et s'adapte à la nature de la classe testée grâce à un système de *Skills* dynamiques.

Les tests générés sont déposés dans le dossier `tests` en répliquant la structure des sous-dossiers de `src/` (convention PSR4) 
et en distinguant les tests unitaires et fonctionnels.

Exemple :
- `tests/Unit/Service/VatCalculatorTest.php`
- `tests/Functional/Controller/InvoiceControllerTest.php`

---

## Prérequis

Pour générer et valider automatiquement les tests unitaires via le LLM, ce bundle s'appuie sur la présence de **PHPUnit** et **PHPStan** dans les dépendances de développement du projet hôte.

### Dépendances requises

- **PHP** 8.2 ou supérieur
- **Symfony** 6.4 ou 7.x
- **PHPUnit** 10.0 ou supérieur (avec un fichier `phpunit.xml` ou `phpunit.xml.dist` à la racine de l'application)
- **PHPStan** 1.10 ou supérieur

### Installation rapide des outils de développement

Si votre projet ne dispose pas encore de ces outils, installez-les via Composer :

```bash
composer require --dev phpunit/phpunit phpstan/phpstan
```

*(Optionnel) Si vous souhaitez configurer rapidement la suite de tests avec l'écosystème Symfony :*

```bash
composer require --dev symfony/test-pack
```

---

## Installation

Installez le bundle via Composer dans votre projet :

```bash
composer require --dev mika/test-generator-bundle
```

---

## Configuration

Déclarer les variables d'environnement nécessaires dans votre fichier `.env` ou `.env.local` :

### Exemple avec Google Gemini
```env
LLM_PROVIDER="gemini"
LLM_MODEL="gemini-flash-latest" # ou un modèle précis, par ex : "gemini-3.1-flash-lite"
GEMINI_API_KEY="votre_cle_api"
```

### Exemple avec Ollama (modèle local)
```env
LLM_PROVIDER="ollama"
LLM_MODEL="qwen2.5-coder:14b"
OLLAMA_HOST="http://localhost:11434"
```

### Exemple avec Anthropic ou OpenAI
```env
# Anthropic
LLM_PROVIDER="anthropic"
LLM_MODEL="claude-3-5-sonnet-latest"
ANTHROPIC_API_KEY="sk-ant-api03-XXXXX"

# OpenAI
LLM_PROVIDER="openai"
LLM_MODEL="gpt-4o-mini"
OPENAI_API_KEY="sk-proj-XXXXX"
```

*(Optionnel)* Vous pouvez également publier ou créer un fichier `
config/packages/dev/mika_test_generator.yaml` pour personnaliser l'injection des paramètres :

```yaml
mika_test_generator:
    llm_provider: '%env(LLM_PROVIDER)%'
    llm_model: '%env(LLM_MODEL)%'
```

---

## Utilisation

### 1. Spécifications BDD Métier (`llm:generate:spec`)

Afin d'orienter le LLM vers des exigences métiers précises (**Behavior Driven Development** / **Given-When-Then**), 
vous pouvez créer ou générer (via un LLM) un fichier de spécification Markdown (`tests/Specs/NomDeClasseSpec.md`).

#### A. Génération automatique de la spécification par le LLM

La commande `llm:generate:spec` fait analyser le code source de la classe par le LLM 
pour rédiger automatiquement une spécification fonctionnelle complète au format **Markdown** :

```bash
# Génère automatiquement tests/Specs/VatCalculatorSpec.md à partir de la classe
php bin/console llm:generate:spec VatCalculator

# Générer une spec ciblée uniquement sur une méthode
php bin/console llm:generate:spec VatCalculator -m calculateNetAmountFromGross

# Forcer la régénération si un fichier de spec existe déjà
php bin/console llm:generate:spec VatCalculator --force
```

#### B. Structure BDD générée et éditable

Le fichier créé suit la structure **BDD** basée sur le pattern **AAA (Arrange-Act-Assert)** :

| BDD                     | Pattern Test                                  |
|-------------------------|-----------------------------------------------|
| **Given** (Étant donné) | Préparation des données & Mocks (**Arrange**) |
| **When** (Lorsque)      | Exécution de la méthode (**Act**)             |
| **Then** (Alors)        | Vérification des assertions (**Assert**)      |

Vous pouvez librement repasser sur ce fichier Markdown pour ajuster, 
ajouter ou supprimer des scénarios métiers avant de lancer la génération du test.

### 2. Génération automatique de tests (`llm:generate:test`)

La commande s'utilise en fournissant la classe à tester (nom court, FQCN ou chemin relatif) :

```bash
# Recherche automatique dans src/ par nom court :
php bin/console llm:generate:test VatCalculator

# Par FQCN :
php bin/console llm:generate:test "App\Service\VatCalculator"

# Cibler une méthode spécifique :
php bin/console llm:generate:test VatCalculator -m calculateNetAmountFromGross

# Générer un test fonctionnel au lieu d'un test unitaire :
php bin/console llm:generate:test "App\Controller\InvoiceController" --functional

# Utiliser un fichier de spec sur mesure :
php bin/console llm:generate:test VatCalculator -s tests/Specs/CustomVatSpec.md
```

## Resolution automatique de la spécification

Lors du lancement de `llm:generate:test` :
1. Si un fichier `tests/Specs/<Classe>Spec.md` existe déjà, il est automatiquement chargé et injecté dans le contexte du LLM.
2. Si aucune spécification n'existe, le bundle la génère automatiquement à la volée via le LLM, 
   la sauvegarde dans `tests/Specs/`, puis l'injecte pour générer le test.
3. Si vous spécifiez une option `-s` / `--spec`, le fichier désigné est directement utilisé.

> **Fusion et mise à jour itérative** : Si un fichier de test existe déjà pour la classe, 
> le bundle vous propose d'effectuer une fusion intelligente via le LLM. 
> De plus, les tests générés sont exécutés automatiquement avec PHPUnit 
> et les erreurs éventuelles sont réinjectées au LLM jusqu'à l'obtention d'un test passant.

---

## Fonctionnalités clés

- **Repo-Map (AST) & Cache :**
  Le bundle analyse la structure du projet `src/` (namespaces, signatures, interfaces)
  et la fournit en contexte au LLM pour éviter toute hallucination de types ou de dépendances.
  Cette cartographie est mise en cache automatiquement et invalidée lors de modifications du code source.
- **Système de Skills dynamiques :**
  Détection automatique du type de classe à tester (ex : `Command` Symfony, manipulation de fichiers)
  afin d'injecter uniquement les directives de test appropriées
  (utilisation de `CommandTester`, nettoyage dans `tearDown()`, etc.).
- **Boucle d'auto-correction (PHPStan & PHPUnit) :**
  Chaque test généré est exécuté en réel (PHPUnit) puis soumis à une analyse statique (PHPStan).
  En cas d'assertion échouée, d'erreur de typage ou de méthode inexistante, 
  le rapport d'erreur est immédiatement réinjecté dans le LLM pour corriger le code jusqu'à obtention d'un test valide.

> *Dans le cas où le LLM échouerait à réaliser un test validé par PHPUnit et PHPStan, 
> le fichier généré et son rapport d'erreur PHPUnit restent conservés sous :*
> - `var/failed_tests/<Y-m-d_H-i-s>_<className>FailedTest.php`
> - `var/failed_tests/<Y-m-d_H-i-s>_<className>FailedTest.log`
>
> *Exemple pour un test en échec sur la classe `VatCalculator` :*
> - *classe de tests :* `var/failed_tests/2024-01-01_12-34-56_VatCalculatorFailedTest.php`
> - *rapport d'erreur PHPUnit :* `var/failed_tests/2024-01-01_12-34-56_VatCalculatorFailedTest.log`.

---

## Tests fonctionnels

### Prérequis et compatibilité SGBD

Le bundle exige une base de données de test opérationnelle pour générer les tests fonctionnels.

- **SGBD supporté :** En phase de développement, seul **PostgreSQL** est pris en charge. Si la chaîne `DATABASE_URL` ne contient pas `postgresql`, 
  le bundle basculera automatiquement sur la génération exclusive de tests unitaires.
- **Fichier `.env.test` :** Ce fichier est généré automatiquement par **Symfony Flex** lors de l'installation des dépendances de test 
  (ex : `symfony/test-pack`, `phpunit-bridge`). Il est donc présent par défaut dans le projet.

### Gestion du nommage et suffixe de base (`dbname_suffix`)

**Symfony** et **Doctrine** gèrent le nommage des bases de test via la directive `dbname_suffix` dans la configuration de test :

```YAML
# config/packages/test/doctrine.yaml
when@test:
doctrine:
dbal:
# "TEST_TOKEN" est généralement défini par ParaTest
dbname_suffix: '_test%env(default::TEST_TOKEN)%'
```

#### Impact sur la configuration :

- Si `dbname_suffix` est actif : **Doctrine** ajoute automatiquement le suffixe `_test` au nom de base résolu. 
  La valeur de `DATABASE_URL` dans l'environnement de test doit donc conserver le nom de la base principale (ex : `my_app`), 
  sous peine d'obtenir un nom doublonné (`my_app_test_test`).
- Si `dbname_suffix` n'est pas configuré : Le nom de la base doit inclure explicitement le suffixe `_test` (ex : `my_app_test`).

### Résolution et automatisation par le Bundle

Pour s'adapter à la machine du développeur tout en évitant les configurations manuelles complexes, le bundle applique la stratégie suivante :

#### 1. Détection de la configuration existante

Le bundle extrait les identifiants de connexion selon l'ordre de priorité suivant :

1. `.env.test.local` (priorité absolue pour les surcharges locales de test).
2. `.env.local` (pour récupérer les identifiants réels de développement local : utilisateur, mot de passe, port).
3. `.env` (fallback standard versionné).

#### 2. Création automatique de l'environnement de test

Si aucune base de test n'est détectée sur le SGBD, le bundle prend le relais :

1. **Génération du fichier `.env.test.local` (s'il est absent) :**
   - Copie de la variable `DATABASE_URL` issue de `.env.local` (ou `.env`).
   - Ajustement automatique du nom de la base (ajout du suffixe `_test` uniquement si `dbname_suffix` n'est pas détecté dans `config/packages/test/doctrine.yaml`).

2. **Création de la base de données :**
   - Exécution de `php bin/console --env=test doctrine:database:create`.

3. **Initialisation de la structure :**
   - Si **DoctrineMigrationsBundle** et des migrations sont présents, exécution de `php bin/console --env=test doctrine:migrations:migrate --no-interaction`. 
   - Sinon, c'est la commande `php bin/console --env=test doctrine:schema:create` qui est exécutée.
