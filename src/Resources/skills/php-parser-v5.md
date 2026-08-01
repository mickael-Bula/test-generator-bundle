# Skill : Génération de tests pour PhpParser v5+

Lorsque la classe à tester interagit avec `nikic/php-parser` (v5+) :

1. **Visibilité & Modificateurs :**
    - **Interdiction :** N'utilise JAMAIS `Class_::MODIFIER_PUBLIC`, `Class_::MODIFIER_PROTECTED`, `Class_::MODIFIER_PRIVATE`.
    - **Obligation :** Utilise EXCLUSIVEMENT la classe `PhpParser\Modifiers` (ex: `Modifiers::PUBLIC`, `Modifiers::PROTECTED`, `Modifiers::PRIVATE`).

2. **Interdiction STRICTE des tableaux optionnels vides (anti-warning IDE) :**
    - Ne passe JAMAIS la clé `'params' => []` dans les options de `ClassMethod` quand la méthode n'a pas de paramètres.
    - Ne passe JAMAIS de clé avec un tableau vide `[]` (`'stmts' => []`, `'params' => []`, etc.). OMETS totalement la clé.

   **Exemple INTERDIT :**
   ```php
   // INCORRECT : 'params' => [] déclenche une erreur d'analyse statique (array vs Node\Param[])
   $method = new ClassMethod('doPrivate', [
       'flags' => Modifiers::PRIVATE,
       'params' => [],
       'returnType' => new Identifier('string'),
   ]);
