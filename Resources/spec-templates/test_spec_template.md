<!--
================================================================================
CONSIGNES POUR LA RÉDACTION DE LA SPÉCIFICATION :
1. Remplacez toutes les variables {variableName} par vos vraies valeurs métiers.
2. Supprimez les scénarios, méthodes ou sections non utilisés.
3. Conservez la structure "Étant donné / Lorsque / Alors".
================================================================================
-->

# Spécification de test : {className}::{methodName}()

## Contexte & Directives de la méthode
<!-- Préciser ici les dépendances spécifiques à cette méthode ou le contexte métier -->
- **Méthode ciblée :** `{methodName}()`
- **Mocks & Dépendances :** <!-- Indiquer les services à mocker pour ce test. -->
- **Prérequis métiers :**
  <!-- Exemples :
  - Taux de TVA appliqué par défaut = 20%
  - L'utilisateur de test doit avoir le rôle ROLE_ADMIN
  -->

---

## Scénario 1 : {nominalScenarioTitle}
<!-- Titre explicite, ex: Calcul classique avec paramètres valides. -->
- **Étant donné** {initialState} <!-- ex: un montant HT de 100.0 et un taux de TVA de 20%. -->
- **Lorsque** j'appelle `{methodName}()` avec {validParameters}
- **Alors** {expectedResult} <!-- ex : le résultat retourné doit être exactement 20.0. -->

## Scénario 2 : {errorScenarioTitle}
<!-- Titre explicite, ex : Gestion d'une valeur invalide -->
- **Étant donné** {invalidState} <!-- ex : un paramètre hors limites ou négatif. -->
- **Lorsque** j'appelle `{methodName}()` avec {invalidParameters}
- **Alors** une exception `{ExpectedExceptionClass}` doit être levée <!-- ex : InvalidArgumentException. -->

## Scénario 3 : {edgeScenarioTitle}
<!-- Titre explicite, ex : Comportement aux limites (Edge case). -->
- **Étant donné** {boundaryState} <!-- ex : un tableau vide ou une chaîne nulle. -->
- **Lorsque** j'appelle `{methodName}()` avec {boundaryParameters}
- **Alors** {expectedBoundaryResult} <!-- ex : la méthode retourne null sans lever d'erreur. -->
