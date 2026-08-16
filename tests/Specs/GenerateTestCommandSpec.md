# Spécification : GenerateTestCommand

## Description
La commande `app:generate-test` permet de générer ou de fusionner des tests PHPUnit (unitaires ou fonctionnels) pour une classe PHP ciblée en s'appuyant sur un LLM et en assurant une boucle de rétroaction avec validation automatique.

---

## 1. Prérequis et validation de l'environnement

### 1.1. Absence de binaire PHPUnit
- **Étant donné que** PHPUnit n'est pas installé sur le projet hôte (absence de `vendor/bin/phpunit` et `vendor/bin/simple-phpunit`),
- **Quand** la commande est exécutée avec une classe valide,
- **Alors** la commande affiche un message d'erreur explicatif et se termine avec le code de retour `Command::FAILURE`.

### 1.2. Absence de fichier de configuration PHPUnit
- **Étant donné que** le binaire PHPUnit existe mais qu'aucun fichier de configuration (`phpunit.xml`, `phpunit.xml.dist` ou `phpunit.dist.xml`) n'est présent à la racine du projet,
- **Quand** la commande est exécutée,
- **Alors** la commande affiche une erreur listant les fichiers recherchés et se termine avec le code `Command::FAILURE`.

---

## 2. Validation des arguments et options

### 2.1. Incompatibilité des flags `--unit` et `--functional`
- **Étant donné que** l'utilisateur fournit simultanément les options `--unit` (`-u`) et `--functional` (`-f`),
- **Quand** la commande est exécutée,
- **Alors** elle affiche une erreur d'incompatibilité et se termine immédiatement avec `Command::FAILURE`.

### 2.2. Type de test par défaut
- **Étant donné que** ni `--unit` ni `--functional` ne sont spécifiés,
- **Quand** la commande est exécutée,
- **Alors** le type de test sélectionné est par défaut `TestType::UNIT`.

### 2.3. Résolution et existence de la classe source
- **Étant donné que** l'argument `class` pointe vers une classe inexistante ou un fichier introuvable,
- **Quand** la commande est exécutée,
- **Alors** `ClassResolver` ou le contrôle d'existence du fichier échoue, un message d'erreur est affiché et la commande se termine avec `Command::FAILURE`.

---

## 3. Déroulement du processus de génération

### 3.1. Prise en compte des options optionnelles (`model`, `spec`, `method`)
- **Étant donné** une classe cible valide,
- **Quand** les options optionnelles `--model`, `--spec` et `--method` sont fournies,
- **Alors** :
    - Le modèle passé en option surpasse le modèle par défaut issu de `LlmClientFactory`.
    - La spécification métier est résolue via `SpecResolver` et injectée dans le contexte du LLM.
    - La méthode ciblée est communiquée à `TestGenerator`.

### 3.2. Traitement d'un nouveau fichier de test
- **Étant donné que** le fichier de test de destination n'existe pas encore,
- **Quand** la génération par le LLM réussit,
- **Alors** :
    - Le répertoire cible est créé si nécessaire via `mkdir`.
    - Les en-têtes dynamiques (namespace, imports, etc.) sont ajustés via `replaceDynamicHeadersInTestCode`.
    - Le code est écrit dans le fichier destination.
    - Un message de succès avec le chemin relatif du fichier est affiché.
    - La commande se termine avec `Command::SUCCESS`.

---

## 4. Interaction et fusion sur un fichier de test existant

### 4.1. Annulation de la fusion par confirmation utilisateur
- **Étant donné que** le fichier de test de destination existe déjà et qu'aucune option `--method` n'a été spécifiée,
- **Quand** l'utilisateur refuse la confirmation ("Voulez-vous lancer la fusion automatique... ?"),
- **Alors** la génération est annulée avec un message explicatif et la commande se termine avec `Command::SUCCESS`.

### 4.2. Annulation si le fichier existant a des modifications non commitées (Git dirty)
- **Étant donné que** le fichier de test existant contient des modifications non versionnées (`git status --porcelain` non vide),
- **Quand** l'utilisateur refuse la confirmation d'écrasement temporaire,
- **Alors** la méthode `checkTestFileIsClean` retourne un échec et la commande s'arrête immédiatement avec `Command::FAILURE`.

### 4.3. Fusion et mise à jour réussie d'un test existant
- **Étant donné que** le fichier de test existe et que toutes les validations (Git propre, confirmation) sont validées,
- **Quand** la génération est exécutée,
- **Alors** :
    - Les en-têtes du code existant sont d'abord préparés via `replaceDynamicHeadersInExistingTestCode`.
    - Le code existant est transmis à `TestGenerator::generateForClass`.
    - Le résultat fusionné est écrit dans le fichier.
    - Une section d'information de sécurité et de revue de code (`git diff` / `git restore`) est affichée à l'utilisateur.

---

## 5. Gestion des exceptions de génération LLM

### 5.1. Capture des erreurs `RuntimeException` et `TestCorrectionException`
- **Étant donné que** l'appel à `TestGenerator::generateForClass` lève une `RuntimeException` ou une `TestCorrectionException` (échec de Repo-Map ou échec de la boucle de correction PHPUnit),
- **Quand** l'exception est interceptée,
- **Alors** la commande affiche le message d'erreur retourné par l'exception et se termine avec `Command::FAILURE`.
