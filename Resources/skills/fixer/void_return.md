# SKILL : CORRECTION DES TYPES DE RETOUR VOID (IncompatibleReturnValueException)

Le rapport d'erreur indique qu'une méthode déclarée `void` ne peut pas retourner de valeur (ex: "Method ... may not return value of type ..., its declared return type is 'void'").

CONSIGNES DE CORRECTION :
1. Vérifie la signature de la méthode concernée dans la Repo-Map.
2. Supprime TOUT appel à `->willReturn(...)` ou `->willReturnCallback(...)` sur le mock de cette méthode.
3. Conserve uniquement la vérification d'appel si nécessaire :
   ```php
   $this->mock->expects($this->once())
       ->method('nomDeLaMethode')
       ->with(...);