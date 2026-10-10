# SKILL : CORRECTION DES ERREURS DE SYNTAXE PHP (Syntax Error)

Le validateur de code a détecté une erreur de syntaxe PHP fatale bloquant l'exécution.

CONSIGNES DE CORRECTION :
1. **Analyse de la ligne critique** : Repère la ligne signalée par le rapport d'erreur. Les causes les plus fréquentes sont :
    - Un attribut (`#[...]`) placé par erreur dans les arguments d'une instanciation (`new`).
    - Une parenthèse, une accolade ou un point-virgule manquant.
    - Une variable mal échappée ou un guichet mal fermé dans une chaîne.
2. **Règles absolues d'anti-crash** :
    - N'échappe **jamais** les variables avec des antislashs (écris `$this` et non `\$this`).
    - Vérifie la fermeture systématique de toutes les chaînes de caractères et des blocs de code.
3. **Restitution** : Corrige uniquement l'erreur de syntaxe en veillant à ne pas altérer la structure logique du test.