# Rédaction des fichiers de spécification de test

Ce guide explique comment rédiger des fichiers de spécification Markdown 
pour piloter la génération de vos tests PHPUnit via le bundle `mika/test-generator-bundle`.

---

## Principes clés

Lorsqu'un fichier de spécification est transmis à la commande de génération, le LLM s'appuie strictement sur son contenu pour construire les cas de test PHPUnit.

Pour obtenir les meilleurs résultats :
1. **Conservez la structure BDD :** La syntaxe `Étant donné / Lorsque / Alors` permet au LLM d'identifier la préparation (Arrange), l'exécution (Act) et l'assertion (Assert).
2. **Utilisez des types et exceptions explicites :** Préférez préciser `InvalidArgumentException` plutôt que « une erreur ».
3. **Supprimez les sections inutilisées :** Si une méthode n'a pas de cas d'erreur ou de cas limite (Edge Case), supprimez la section correspondante pour éviter d'inciter le LLM à inventer des cas de test artificiels.
4. **Commentez vos consignes d'aide :** Les commentaires HTML (`<!-- ... -->`) sont automatiquement ignorés par le bundle et ne consomment pas de tokens auprès du LLM.

---

## Modèles (Templates) disponibles

Le bundle fournit des gabarits prêts à l'emploi dans son répertoire `Resources/prompts/` (ou accessibles via la commande interactive) :

* **Spécification par classe (`test_spec_class.md`) :** Pour couvrir l'ensemble des méthodes publiques d'un service.
* **Spécification par méthode (`test_spec_method.md`) :** Pour cibler ou ajouter des tests sur une méthode spécifique.

---

## Exemple concret : Avant / Après

Voici l'illustration du processus de rédaction pour tester un service `VatCalculator`.

### 1. Gabarit initial fourni par le bundle (Avant modification)

```markdown
<!-- 
================================================================================
CONSIGNES :
1. Remplacez les variables {variableName} par vos vraies valeurs.
2. Supprimez les sections non utilisées.
================================================================================
-->

# Spécification de test : {className}

## Contexte & Directives du test
<!-- Préciser le contexte global -->
- **Service ciblé :** `{className}`
- **Mocks & Dépendances :** <!-- Indiquer les services à mocker -->

---

## Méthode : {methodName1}

### Scénario 1 : {nominalScenarioTitle1}
- **Étant donné** {initialState}
- **Lorsque** j'appelle la méthode `{methodName1}` avec {parameters}
- **Alors** {expectedResult}

### Scénario 2 : {errorScenarioTitle1}
- **Étant donné** {invalidState}
- **Lorsque** j'appelle la méthode `{methodName2}` avec {invalidParameters}
- **Alors** une exception `{ExpectedExceptionClass}` doit être levée