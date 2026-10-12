# SKILL : INITIALISATION DES ENTITÉS ET PARAMÈTRES NULL (TypeError / null given)

Le rapport d'erreur indique qu'un argument de type primitif (ex : `int`) a reçu `null`, provoquant un `TypeError` lors de l'appel d'une méthode de repository ou de service
(ex : "Argument #1 (...) must be of type int, null given").

CONSIGNES DE CORRECTION :
1. **Identification de l'objet source** : Repère l'entité (ex : `User`) ou l'objet de contexte manipulé sous la section `// ÉTANT DONNÉ`.
2. **Ajout des mutateurs manquants** : L'objet a été instancié via `new` mais ses propriétés requises n'ont pas été alimentées. Appelle explicitement les setters correspondants sous `// ÉTANT DONNÉ` pour fixer l'état attendu par le scénario
   (ex : `$user = new User(); $user->setLastCacUpdatedId(10);`).
3. **Vérification des mocks** : Si l'erreur provient d'un mock dont le retour n'est pas configuré, ajoute le `->willReturn(...)` approprié.