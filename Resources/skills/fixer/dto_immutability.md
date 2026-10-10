# SKILL : INTERDICTION FORMELLE DES MÉTHODES DE MODIFICATION SUR LES OBJETS IMMUTABLES (with* / set* / with)

Le rapport d'erreur indique un appel à une méthode inexistante sur un DTO ou un Value Object immuable (ex : `Call to undefined method ...::with...()`).

CONSIGNES DE CORRECTION IMPÉRATIVES :
1. **Absence totale de mutateurs ou de méthodes fluent** : Les DTOs et Value Objects de ce projet sont immutables. 
   Il est **interdit** d'appeler des méthodes de type `->with...()` ou `->set...()` après l'instanciation de l'objet.
2. **Passage de toutes les valeurs dans le constructeur unique** :
    - Supprime définitivement l'appel à la méthode `->with...()`.
    - Toutes les données nécessaires à l'objet (y compris les indicateurs, prix ou attributs secondaires) 
      **doivent** être passées directement en arguments lors de l'appel initial à `new MonDto(...)`, 
      en respectant scrupuleusement la signature du constructeur de la classe source.