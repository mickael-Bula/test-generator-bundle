# Directives d'architecture et de sécurité - PHPUnit 11 & PHP 8.2+

## 1. Périmètre de test et visibilité des méthodes
- **Interface publique uniquement :** N'appelle JAMAIS de méthode `private` ou `protected` sur la classe testée (SUT).
- **Test indirect :** Valide le comportement des méthodes privées/protégées uniquement à travers les effets de bord de l'interface publique.

## 2. Définition et manipulation du SUT (Subject Under Test)
- **Instanciation réelle :** Le SUT doit être une instance réelle créée via `new TargetClass(...)`.
- **Interdiction de mock sur le SUT :** Ne configure JAMAIS d'attente (`expects()`, `method()`, `willReturn()`) sur l'instance de la classe à tester. 
  Les attentes s'appliquent EXCLUSIVEMENT sur ses dépendances mockées.

## 3. Strict respect du typage PHP 8
- **Types stricts (`declare(strict_types=1)`) :** Aligne scrupuleusement les types d'arguments transmis aux setters, 
  constructeurs et DTOs sur les signatures réelles du code source.
- **Différenciation Int/Float/String :**
  - Si un paramètre attend un `float`, passe un float explicite (ex : `100.0` et non `100`).
  - Si un paramètre attend une `string` (ex : montants `bcmath` / `Decimal`), passe une chaîne (`'100.0'` et non `100`).
- **Enums PHP 8 :** Passe TOUJOURS le cas d'Enum natif (ex : `MyEnum::CAS_A`) et jamais sa valeur scalaire brute (`'CAS_A'`), 
  sauf si le setter attend explicitement un type scalaire.

## 4. Mocks, Stubs et Repositories
- **Types de retour des Mocks :** La valeur retournée par `willReturn()` ou `willReturnCallback()` doit correspondre au type de retour déclaré de la méthode mockée 
  (respect du typage de retour).
- **Interdiction de méthode fantôme :** Ne mocke JAMAIS une méthode qui n'existe pas dans la classe ou l'interface de la dépendance 
  (vérifie le contrat d'interface et la classe concrète).
- **Simulations d'identifiants Doctrine :** N'appelle pas de méthode `setId()` sur une entité si celle-ci n'est pas déclarée dans la classe. 
  Si un ID est requis, passe-le au constructeur ou utilise la réflexion PHP.

## 5. Intégrité des méthodes et getters
- **Getters et propriétés :** N'invente pas de nom de getter ou de méthode d'accès 
  (ex : ne déduis pas `isX()` ou `getX()` sans avoir vérifié le code source fourni).
- **Méthodes statiques et utilitaires :** Vérifie si les utilitaires appelés sont statiques ou issus de dépendances injectées.

## 6. Formatage strict du code PHP
- **Aucun formatage Markdown résiduel :** Le fichier généré doit être du code PHP brut. 
  Il ne doit contenir AUCN balisage de clôture Markdown (` ``` ` ou ` ```php `).
- **Format de fichier :** Démarre le fichier par `<?php` et termine-le directement sur la dernière accolade de fermeture `}` de la classe de test.