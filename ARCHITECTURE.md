## Les prompts

Les prompts sont déclarés dans le dossier `PromptBuilder`.
Il existe des prompts spécifiques pour chaque type de tests et chaque outil (PHPUnit, PHPStan, Specs, test unitaire, fonctionnel).
Tous les prompts implémentent une même interface, ce qui permet leur appel à l'aide d'un resolver.

Une fois le prompt récupéré, il vient initialiser, compléter ou remplacer en partie le prompt soumis au LLM.

Chaque prompt peut être enrichi par des skills, activés à partir de recherches textuelles dans les sorties du LLM.
Il existe des skills pour PHPUnit, pour PHPStan, pour le parser, pour la syntaxe PHP ou encore pour le fixer.

Certains skills (ex : `unit_test_safety.md`) sont systématiquement ajoutés au prompt principal. Ils visent à fixer des règles générales.

## La factory de LLM

## Repo-Map

Elle est ajoutée dans le prompt depuis `PhpUnitFixerPromptBuilder` avec `use RepoMapSystemMessageTrait;`.

## Les Skills personnalisés

Il faut ajouter la possibilité de déterminer un chemin pour ajouter des Skills personnalisés
Ce chemin sera à injecter dans la classe 

## Chargements des Skills et des Détecteurs

Les Skills se trouvent dans le dossier `Resources/skills` et `Resources/skills/fixer`.
Ils sont appelés dans `TestGenerator::resolveForClass`.
Appelés également depuis la construction du prompt PHPUnit : `PhpUnitFixerPromptBuilder::buildPrompt`.

Les détecteurs sont déclarés dans et appelés depuis `FixerSkillResolver::resolveFromErrorOutput`.
Appelés depuis `PhpUnitFixerPromptBuilder::buildSystemMessage`.

## Améliorations possibles

L'appel des détecteurs se fait lors de la construction du prompt : pour PHPUnit, dans PhpUnitFixerPromptBuilder
Est-il nécessaire de transmettre la Repo-Map dans ce prompt, sachant que l'on précise le type d'erreur et sa solution ?

## TODO

