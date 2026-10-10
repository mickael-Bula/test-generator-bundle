## Les prompts

Les prompts sont déclarés dans le dossier `PromptBuilder`.
Il existe des prompts spécifiques pour chaque type de tests et chaque outil (PHPUnit, PHPStan, Specs, test unitaire, fonctionnel).
Tous les prompts implémentent une même interface, ce qui permet leur appel à l'aide d'un resolver.

Une fois le prompt récupéré, il vient initialiser, compléter ou remplacer en partie le prompt soumis au LLM.

Chaque prompt peut être enrichi par des skills, activés à partir de recherches textuelles dans les sorties du LLM.
Il existe des skills pour PHPUnit, pour PHPStan, pour le parser, pour la syntaxe PHP ou encore pour le fixer.

Certains skills (ex : `unit_test_safety.md`) sont systématiquement ajoutés au prompt principal. Ils visent à fixer des règles générales.

## La factory de LLM

