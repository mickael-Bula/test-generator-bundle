# SKILL : INTERDICTION ABSOLUE DES ATTRIBUTS LORS DE L'INSTANCIATION (Syntax Error)

Le rapport d'erreur indique une erreur de syntaxe PHP fatale (`Syntax error, unexpected T_VARIABLE`) causée par l'utilisation d'un attribut 
(ex : `#[TaggedIterator(...)]`, `#[Autowire]`) lors d'une instanciation d'objet.

CONSIGNES DE CORRECTION (CRITIQUE) :
1. **Règle syntaxique non négociable** : En PHP, il est **strictement impossible** de placer un attribut devant un argument lors d'un appel à `new` ou d'une fonction.
   - ❌ **INTERDIT** : `new MaClasse(#[Attribut] $argument)`
   - ✅ **AUTORISÉ** : `new MaClasse($argument)`
2. **Action corrective immédiate** :
   - Supprime instantanément tout attribut (`#[...]`) présent entre les parenthèses d'un constructeur ou d'un appel de fonction.
   - Ne conserve que la variable, le tableau ou la valeur pure. L'attribut appartient exclusivement à la déclaration de la classe source, jamais au code du test.