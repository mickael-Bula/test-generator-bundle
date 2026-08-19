# SKILL : GESTION DES CLASSES FINALES (ClassIsFinalException)

Le rapport d'erreur indique qu'une classe est déclarée 'final' et ne peut pas être doublée 
(ex : "Class ... is declared 'final' and cannot be doubled").

CONSIGNES DE CORRECTION :
1. **RÈGLE D'OR** : Ne réintroduis JAMAIS `$this->createMock()` ou `$this->createConfiguredMock()` sur cette classe finale, même pour résoudre un autre problème.
2. **Modifie l'initialisation** :
    - Supprime la création du mock dans `setUp()` ou dans le corps du test.
    - Instancie la vraie classe directement (ex : `$instance = new LaClasseFinale(...)`).
3. **Adapte les types** :
    - Si la classe de test possède une propriété typée `MockObject&LaClasseFinale`, retire le type intersection `MockObject&` pour utiliser le type concret `LaClasseFinale`.
4. **Instanciation des dépendances** :
    - Si la classe finale requiert des arguments dans son constructeur, instancie-les ou moque leurs interfaces/classes non finales si applicables.