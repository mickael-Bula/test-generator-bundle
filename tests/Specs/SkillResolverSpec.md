# Spécification du Test Unitaire : `SkillResolver`

## 1. Contexte et Objectif de la Classe
La classe `SkillResolver` est responsable d'analyser une classe PHP (via son FQCN et/ou son code source brut `$classCode`) afin d'identifier les compétences ("skills") pertinentes à lui associer (fichiers Markdown).
Elle charge les skills natifs du bundle situés dans le répertoire natif et les combine avec d'éventuels skills personnalisés issus d'un répertoire configuré (`$customSkillsDir`).

---

## 2. Dépendances et Entrées du Constructeur
- `?string $customSkillsDir = null` : Répertoire optionnel contenant des fichiers `.md` personnalisés.
- `?string $nativeSkillsDir = null` : Répertoire optionnel des skills natifs. Si `null`, calculé par défaut (`Resources/skills` du bundle).

---

## 3. Matrice de Test des Cas d'Usage (`resolveForClass`)

### A. Détection des Skills Natifs
1. **Commandes Symfony (`symfony_command.md`) :**
    - **Cas A1 :** La classe hérite directement de `Symfony\Component\Console\Command\Command` (via Reflection / `class_exists`).
    - **Cas A2 :** Le code source contient la syntaxe `extends Command`.
    - **Cas A3 :** Le code source contient l'attribut PHP 8 `#[AsCommand(...)`.
    - **Cas A4 :** Aucun de ces critères n'est rempli -> le skill `symfony_command.md` ne doit PAS être inclus.

2. **Opérations Système de Fichiers (`filesystem_test.md`) :**
    - **Cas B1 :** Prescence de fonctions natives (ex: `file_get_contents`, `file_put_contents`, `mkdir`, `sys_get_temp_dir`, `unlink`).
    - **Cas B2 :** Utilisation du composant Symfony Finder (`use Symfony\Component\Finder\Finder;`).
    - **Cas B3 :** Utilisation du composant Symfony Filesystem (`use Symfony\Component\Filesystem\Filesystem;`).
    - **Cas B4 (Faux Positifs) :** Présence de termes similaires comme `UserFinder` ou `MyFilesystem` -> Ne doit PAS déclencher le skill.

3. **PhpParser v5 (`php-parser-v5.md`) :**
    - **Cas C1 :** Utilisation du namespace `PhpParser\` dans le code source.
    - **Cas C2 :** Présence d'un `use PhpParser;`.

### B. Chargement et Dédoublonnage
- **Cas D1 (Dédoublonnage) :** Si un code source déclenche plusieurs fois la même règle, le fichier Markdown associé ne doit être chargé et concaténé qu'une seule fois.
- **Cas D2 (Fichier natif introuvable) :** Si le skill détecté n'existe pas physiquement sur le disque, la méthode doit ignorer le fichier sans générer d'erreur ni ajouter de contenu vide.

### C. Chargement des Skills Personnalisés (`$customSkillsDir`)
- **Cas E1 :** Si `$customSkillsDir` est `null` ou pointe vers un dossier inexistant, aucun skill personnalisé n'est chargé.
- **Cas E2 :** Si `$customSkillsDir` contient des fichiers `.md`, leur contenu doit être chargé et ajouté à la fin du résultat (séparé par des doubles sauts de ligne `\n\n`).

---

## 4. Consignes Strictes pour la Génération PHPUnit

1. **Isolation des I/O (Système de Fichiers) :**
    - Utilise `sys_get_temp_dir()` ou un répertoire temporaire créé dans `setUp()` et nettoyé dans `tearDown()` pour tester le comportement avec de véritables fichiers Markdown.

2. **Structure des Assertions :**
    - Pour les tests vérifiant le contenu renvoyé par `resolveForClass()`, utilise `$this->assertStringContainsString('contenu_attendu', $result)` au lieu de comparaisons exactes d'égalités de chaînes lorsque le chemin physique dépend de l'environnement.

3. **Règles de Syntaxe (CRITIQUE) :**
    - Si une assertion ou un extrait de code source contient le symbole `$`, entoure TOUJOURS l'argument avec des apostrophes simples (`'...'`) pour éviter toute évaluation par PHP.
    - Pour les assertions contenant des sauts de ligne (`\n`), utilise impérativement des guillemets doubles (`"..."`).
