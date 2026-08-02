# Test Generator Bundle pour Symfony

Le **Test Generator Bundle** intègre dans votre application Symfony un outil de génération automatique de tests unitaires
et fonctionnels PHPUnit piloté par un LLM (via Symfony AI Platform).

Il prend en compte le contexte global de votre projet (Repo-Map / AST),
supporte la rédaction de spécifications en Markdown (BDD)
et s'adapte à la nature de la classe testée grâce à un système de *Skills* dynamiques.

---

## Prérequis

- **PHP** 8.2 ou supérieur
- **Symfony** 6.4 ou 7.x
- **PHPUnit** 10.0 ou supérieur configuré sur le projet hôte,
  avec la présence d'un fichier `phpunit.xml` ou `phpunit.xml.dist` à sa racine

  (facilement généré via `composer require --dev symfony/test-pack`).

---

## Installation

Installez le bundle via Composer dans votre projet :

```bash
composer require mika/test-generator-bundle --dev
```

Assurez-vous également d'installer le pont (bridge) **Symfony AI Platform**
correspondant au fournisseur de LLM que vous souhaitez utiliser :

```bash
# Exemple pour Google Gemini
composer require symfony/ai-gemini-platform

# Ou pour Ollama (modèles locaux)
composer require symfony/ai-ollama-platform

# Ou pour Anthropic / OpenAI / OpenRouter
composer require symfony/ai-anthropic-platform
composer require symfony/ai-open-ai-platform
```

---

## Configuration

Déclarer les variables d'environnement nécessaires dans votre fichier `.env` ou `.env.local` :

### Exemple avec Google Gemini
```env
LLM_PROVIDER="gemini"
LLM_MODEL="gemini-flash-latest"
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

### 1. Génération automatique de tests (`app:generate-test`)

La commande s'utilise en fournissant la classe à tester (nom court, FQCN ou chemin relatif) :

```bash
# Recherche automatique dans src/ par nom court :
php bin/console app:generate-test VatCalculator

# Par FQCN :
php bin/console app:generate-test "App\Service\VatCalculator"

# Cibler une méthode spécifique :
php bin/console app:generate-test VatCalculator -m calculateNetAmountFromGross

# Générer un test fonctionnel au lieu d'un test unitaire :
php bin/console app:generate-test "App\Controller\InvoiceController" --functional
```

> **Fonctionnement itératif :** Que ce soit pour la création d'un nouveau fichier ou l'injection d'une méthode dans un test existant,
> la commande exécute PHPUnit et réinjecte automatiquement les erreurs au LLM jusqu'à l'obtention d'un test passant.

---

### 2. Guide des spécifications BDD (`app:test-spec`)

Pour guider le LLM avec des exigences métiers précises (approche **Behavior Driven Development** (BDD) / **Given-When-Then**) :

#### A. Générer le squelette Markdown

```bash
php bin/console app:test-spec VatCalculator
```
Un fichier `tests/Specs/VatCalculatorSpec.md` sera créé.

#### B. Rédiger le scénario

Remplissez la structure **BDD** en faisant correspondre vos exigences au pattern **AAA (Arrange-Act-Assert)** :

| BDD                     | Pattern Test                                  |
|-------------------------|-----------------------------------------------|
| **Given** (Étant donné) | Préparation des données & Mocks (**Arrange**) |
| **When** (Lorsque)      | Exécution de la méthode (**Act**)             |
| **Then** (Alors)        | Vérification des assertions (**Assert**)      |

#### C. Lancer la génération basée sur la spécification

```bash
# Convention automatique (cherche tests/Specs/VatCalculatorSpec.md) :
php bin/console app:generate-test VatCalculator --spec

# Passer une consigne rapide directement en ligne de commande :
php bin/console app:generate-test VatCalculator --spec="Lever une exception si le montant HT est négatif"
```

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
