<!--
================================================================================
CONSIGNES POUR LA RÉDACTION DE LA SPÉCIFICATION :
1. Remplacez toutes les variables {variableName} par vos vraies valeurs métiers.
2. Supprimez les scénarios, méthodes ou sections non utilisés.
3. Conservez la structure "Étant donné / Lorsque / Alors".
================================================================================
-->

# Spécification de test : {className}

## Contexte & Directives du test
<!-- Préciser ici le contexte technique et métier global pour la classe à tester. -->
- **Service ciblé :** `{className}`
- **Mocks & Dépendances :** <!-- Indiquer les services à mocker ou les stubs à injecter. -->
- **Prérequis métiers :**
  <!-- Exemples :
  - Taux de TVA appliqué par défaut = 20%
  - L'utilisateur de test doit avoir le rôle ROLE_ADMIN
  -->

---

## Méthode : {methodName1}

### Scénario 1 : {nominalScenarioTitle1}
- **Étant donné** {initialState} <!-- ex : un état initial valide / une entité correctement configurée. -->
- **Lorsque** j'appelle la méthode `{methodName1}` avec {parameters} <!-- ex : les arguments $id = 42 et $amount = 100. -->
- **Alors** {expectedResult} <!-- ex : le résultat retourné doit être égal à 120,0 et l'événement X doit être émis. -->

---

## Méthode : {methodName2}

### Scénario 1 : {errorScenarioTitle1}
- **Étant donné** {invalidState} <!-- ex : un argument négatif / un utilisateur non authentifié. -->
- **Lorsque** j'appelle la méthode `{methodName2}` avec {invalidParameters}
- **Alors** une exception `{ExpectedExceptionClass}` doit être levée <!-- ex : InvalidArgumentException avec le message "Montant invalide". -->
