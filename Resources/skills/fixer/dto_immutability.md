# SKILL : GESTION DES DTOs ET VALUE OBJECTS IMMUTABLES (Arguments de constructeur et interdiction des mutateurs)

Le rapport d'erreur indique soit un `TypeError` (ex : `null given where float expected`), 
soit un appel à une méthode inexistante (ex : `with*`, `set*`), car un DTO ou Value Object immuable a été mal instancié.

CONSIGNES DE CORRECTION :
1. **Interdiction formelle des mutateurs** : Ne chaîne **jamais** de méthode de modification (`->with()`, `->set()`, etc.) sur un DTO ou Value Object immuable.
2. **Exhaustivité des arguments du constructeur** :
   - Toutes les propriétés requises (y compris les valeurs optionnelles ou secondaires comme les prix, indicateurs ou statuts) 
     **doivent** être fournies directement lors de l'appel initial au constructeur (`new MonDto(val1, val2, val3, ...)`).
   - Consulte impérativement la signature exacte du constructeur de la classe dans la **REPO-MAP** pour connaître l'ordre, 
     le type et le nombre exact de paramètres attendus.
3. **Zéro valeur nulle non intentionnelle** : N'omet aucun argument obligatoire sous peine de provoquer des erreurs de type (`TypeError`) 
   lors du traitement par les services de l'application.