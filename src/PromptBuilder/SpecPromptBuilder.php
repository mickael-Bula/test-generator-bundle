<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Reflection\EnumCases;

class SpecPromptBuilder
{
    /**
     * Génère la liste des messages (system + user) pour le LLM.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(
        string $classCode,
        string $fqcn,
        ?string $methodName = null,
        string $type = 'unit',
    ): array {
        return [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($type)],
            ['role' => 'user', 'content' => $this->buildUserPrompt($classCode, $fqcn, $methodName, $type)],
        ];
    }

    private function buildSystemPrompt(string $type): string
    {
        $isFunctional = 'functional' === strtolower($type);
        $testTypeLabel = $isFunctional ? 'Functional' : 'Unit';

        $specificInstructions = $isFunctional
            ? <<<INSTRUCTIONS
3. **Analyse des flux HTTP / Intégration** :
   - Identifie les routes, méthodes HTTP (GET, POST...), codes de statut (200, 400, 404, 500) et formats de réponse.
   - Identifie les contrôles d'accès/sécurité (ex : rôles requis, authentification).
   - Ne cherche PAS à mocker les services internes sauf les services tiers externes (ex : API de paiement, envoi d'emails).
4. **Isolation et Mocks** :
   - Dans un test fonctionnel, **ne mocke PAS** les services internes (base de données, services métier). Utilise le container de services réel.
   - Indique UNIQUEMENT les services tiers externes dans `dependenciesToMock` (ex : passerelle de paiement API, service d'envoi de mail externe, API météo).
INSTRUCTIONS
            : <<<INSTRUCTIONS
3. **Détection des Data Providers PHPUnit** :
   - Identifie les méthodes qui exécutent la même logique sur des ensembles de données variés.
   - Regroupe systématiquement ces cas répétitifs sous forme de **Data Provider** (`dataProviders`).
   - **Règle de cohérence stricte** : Si un cas de test (`testCase`) indique un `dataProviderName` non nul (et `usesDataProvider: true`), 
     l'objet Data Provider correspondant DOIT obligatoirement être déclaré et détaillé dans le tableau `dataProviders` de la même méthode. 
     Inversement, si aucun Data Provider n'est défini, passe `usesDataProvider: false` et `dataProviderName: null`.
4. **Isolation et Mocks** :
   - Les seules candidates à `dependenciesToMock` sont les dépendances injectées dans le CONSTRUCTEUR.
   - Les paramètres de méthodes qui sont des entités, DTO ou value objects sont de vraies instances décrites dans `inputs` : jamais des mocks.
   - Dans `mockExpectations`, `dependency` doit être l'un des FQCN de `dependenciesToMock`. 
     Il ne désigne JAMAIS la classe testée (`targetClass`) : on ne mocke aucune méthode de la classe testée, même si la méthode testée l'appelle.
   - Indique quelles dépendances doivent être mockées pour chaque cas de test (`mockExpectations`).
INSTRUCTIONS;

        return <<<PROMPT
Tu es un Agent Analyste de Tests PHP spécialisé dans l'architecture logicielle, le DDD et l'assurance qualité (QA).
Ton unique rôle est d'analyser le code source d'une classe PHP et de concevoir une matrice de cas de test exhaustive sous la forme d'un objet JSON strict.

### CONSIGNES D'ANALYSE
1. **Analyse de la structure** :
   - Examine la classe, ses dépendances (constructeur), ses méthodes publiques et leurs contrats (types, PHPDoc).
   - `methods` ne contient QUE les méthodes PUBLIQUES de la classe. Une méthode privée ou protégée n'a aucune entrée, 
     même si elle porte beaucoup de logique : elle n'est pas appelable depuis un test. 
     Ses branches sont couvertes par les cas des méthodes publiques qui l'appellent : 
     utilise-les pour concevoir les `inputs` et les `mockExpectations` de ces cas.
   - `expectedBehavior.returnValue` : si la valeur est le résultat d'un calcul, 
     écris la FORMULE appliquée aux `inputs` (ex. (10 * 100.0 + 5 * 105.0) / (10 + 5)), jamais un résultat calculé de tête.
2. **Couverture des branches (Path Coverage)** :
   - Identifie tous les chemins d'exécution (`if`, `else`, `switch`, `match`, `try/catch`).
   - Identifie les cas nominaux (*happy paths*).
   - Identifie les cas d'erreur (exceptions levées via `throw`).
   - Identifie les cas limites (*edge cases* : `null`, tableaux vides, chaînes vides, nombres négatifs/zéro).
{$specificInstructions}

### RÈGLES STRICTES DE FORMATAGE JSON (CRITIQUE)
- **AUCUNE SYNTAXE PHP DANS LE JSON** : N'utilise JAMAIS d'opérateurs d'association PHP `=>`, ni de tableaux PHP `['key' => 'val']`. 
  Tout doit être du JSON valide (ex : `{"key": "val"}`).
- **Structure des objets et tableaux** : Les tableaux associatifs PHP doivent TOUJOURS être traduits en objets JSON (`{"clé": "valeur"}`).
- **Encadrement strict des clés et valeurs string** : N'oublie jamais les guillemets doubles autour des clés JSON.
- **Strict RAW JSON Output** : Réponds EXCLUSIVEMENT par l'objet JSON brut. N'inclus AUCUN bloc de code Markdown (PAS de ```json ... ```), 
  AUCUN texte d'introduction ni d'explication.

### SCHÉMA JSON OBLIGATOIRE
{
  "targetClass": "Nom complet FQCN de la classe",
  "testType": "{$testTypeLabel}",
  "dependenciesToMock": [
    {
      "class": "FQCN\\\\De\\\\La\\\\Dependance",
      "propertyName": "nomDeLaPropriete"
    }
  ],
  "methods": [
    {
      "name": "nomDeLaMethode",
      "dataProviders": [
        {
          "providerName": "provideDataForNomDeLaMethode",
          "targetTestMethod": "testNomDeLaMethodeWithDataProvider",
          "description": "Explication du périmètre du Data Provider",
          "dataSetKeys": ["param1", "param2", "expectedResult"],
          "dataSets": [
            {
              "label": "Description explicite du jeu de données",
              "providedValues": {
                "param1": {"key": "valeur"},
                "param2": "valeur2",
                "expectedResult": ["a", "b"]
              }
            }
          ]
        }
      ],
      "testCases": [
        {
          "id": "TC_METHOD_01",
          "title": "testNomDeLaMethodeThrowsExceptionOnInvalidInput",
          "description": "Description du cas de test isolé",
          "type": "error",
          "usesDataProvider": false,
          "dataProviderName": null,
          "inputs": {
            "param1": {"invalid": "data"}
          },
          "mockExpectations": [
            {
              "dependency": "FQCN\\\\De\\\\La\\\\Dependance",
              "method": "methodeAppelee",
              "willReturns": "valeurRetournee",
              "willThrow": null
            }
          ],
          "expectedBehavior": {
            "returnValue": null,
            "throwsException": "InvalidArgumentException",
            "exceptionMessage": "Message d'erreur attendu"
          }
        }
      ]
    }
  ]
}
PROMPT;
    }

    /**
     * @throws \ReflectionException
     */
    private function buildUserPrompt(string $classCode, string $fqcn, ?string $methodName, string $type): string
    {
        $prompt = sprintf(
            "Voici le code source de la classe PHP à analyser (`%s`) pour générer une suite de tests de type **%s** :\n\n```php\n%s\n```",
            $fqcn,
            strtoupper($type),
            $classCode,
        );

        // Faits calculés par réflexion : méthodes publiques, dépendances du constructeur, cas d'Enum
        $prompt .= $this->referenceBlock($fqcn, $type);

        if ($methodName) {
            $prompt .= sprintf(
                "\n\n ATTENTION : L'utilisateur souhaite cibler en priorité la méthode `%s()`. "
                . 'Concentre ton analyse sur cette méthode et ses interactions.',
                $methodName,
            );
        }

        return $prompt;
    }

    /**
     * Liste des références calculées par Réflexion PHP dans le message utilisateur.
     *
     * @throws \ReflectionException
     */
    private function referenceBlock(string $fqcn, string $type): string
    {
        if (!class_exists($fqcn)) {
            return '';
        }

        $rc = new \ReflectionClass($fqcn);

        $own = static fn (\ReflectionMethod $m): bool => !$m->isConstructor() && !$m->isDestructor()
            && !str_contains((string) $m->getFileName(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

        $list = static fn (array $methods): string => [] === $methods
            ? 'aucune'
            : implode(', ', array_map(static fn (\ReflectionMethod $m): string => $m->getName() . '()', $methods));

        $public = array_filter($rc->getMethods(\ReflectionMethod::IS_PUBLIC), $own);
        $nonPublic = array_filter(
            $rc->getMethods(\ReflectionMethod::IS_PRIVATE | \ReflectionMethod::IS_PROTECTED),
            static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $fqcn,
        );

        $block = "\n\nRÉFÉRENCE CALCULÉE PAR RÉFLEXION (fait foi) :\n"
            . '- Méthodes PUBLIQUES (seules autorisées dans `methods`) : ' . $list($public) . "\n"
            . '- Méthodes non publiques (JAMAIS dans `methods`) : ' . $list($nonPublic) . "\n";

        if ('functional' !== strtolower($type)) {
            $deps = [];
            foreach ($rc->getConstructor()?->getParameters() ?? [] as $p) {
                $t = $p->getType();
                if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()
                    && (class_exists($t->getName()) || interface_exists($t->getName()))) {
                    $r = new \ReflectionClass($t->getName());
                    if (!$r->isInternal() && !$r->isEnum()) {
                        $deps[] = '`' . $t->getName() . '`';
                    }
                }
            }
            $block .= '- Dépendances du constructeur (seules autorisées dans `dependenciesToMock` et `mockExpectations`) : '
                . ([] === $deps ? 'aucune' : implode(', ', $deps)) . "\n";
        }

        $enums = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $p) {
                $type = $p->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && enum_exists($type->getName())) {
                    $enums[$type->getName()] = array_keys(EnumCases::of($type->getName())['cases'] ?? []);
                }
            }
        }
        foreach ($enums as $enum => $names) {
            $block .= sprintf(
                "- Enum `\\%s` : seuls ces cas existent : %s. Dans `inputs`, "
                . "désigne un cas par son nom exact (une chaîne), jamais par un objet ; n'en invente aucun.\n",
                $enum,
                implode(', ', \array_slice($names, 0, 20)),
            );
        }

        return $block;
    }
}
