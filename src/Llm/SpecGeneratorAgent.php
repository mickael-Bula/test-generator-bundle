<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;

readonly class SpecGeneratorAgent
{
    public function __construct(
        private LlmClientFactory $llmFactory,
    ) {
    }

    /**
     * Analyse le code source d'une classe et génère la matrice de cas de test au format JSON.
     *
     * @param string      $classCode  Le code source de la classe PHP à analyser
     * @param string      $fqcn       Le FQCN de la classe (ex : App\Service\VatCalculator)
     * @param string|null $methodName Le nom d'une méthode spécifique (facultatif)
     * @param string|null $model      Le modèle LLM à utiliser (null pour le modèle par défaut)
     * @param string|null $provider   Le provider LLM à utiliser (null pour le provider par défaut)
     *
     * @throws \RuntimeException       Si le LLM échoue ou si le JSON retourné est invalide
     * @throws TestGenerationException
     */
    public function generateSpec(
        string $classCode,
        string $fqcn,
        ?string $methodName = null,
        ?string $type = 'unit',
        ?string $model = null,
        ?string $provider = null,
    ): SpecResultDto {
        // Résolution du client et du modèle via la factory du bundle
        $client = $this->llmFactory->getClient($provider);
        $targetModel = $model ?? $this->llmFactory->getDefaultModel();

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($type)],
            ['role' => 'user', 'content' => $this->buildUserPrompt($classCode, $fqcn, $methodName, $type)],
        ];

        // Appel du LLM
        return $client->callForSpec($messages, $targetModel);
    }

    /**
     * Rédige le prompt système définissant le rôle de l'Agent Spec.
     */
    private function buildSystemPrompt(string $type = 'unit'): string
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
   - Repère les dépendances injectées dans le constructeur ou les méthodes.
   - Indique quelles dépendances doivent être mockées pour chaque cas de test (`mockExpectations`).
INSTRUCTIONS;

        return <<<PROMPT
Tu es un Agent Analyste de Tests PHP spécialisé dans l'architecture logicielle, le DDD et l'assurance qualité (QA).
Ton unique rôle est d'analyser le code source d'une classe PHP et de concevoir une matrice de cas de test exhaustive sous la forme d'un objet JSON strict.

### CONSIGNES D'ANALYSE
1. **Analyse de la structure** : Examine la classe, ses dépendances (constructeur), ses méthodes publiques et leurs contrats (types, PHPDoc).
2. **Couverture des branches (Path Coverage)** :
   - Identifie tous les chemins d'exécution (`if`, `else`, `switch`, `match`, `try/catch`).
   - Identifie les cas nominaux (*happy paths*).
   - Identifie les cas d'erreur (exceptions levées via `throw`).
   - Identifie les cas limites (*edge cases* : `null`, tableaux vides, chaînes vides, nombres négatifs/zéro).
{$specificInstructions}

### FORMAT DE SORTIE
Tu DOIS répondre EXCLUSIVEMENT avec un objet JSON valide, sans aucun texte d'introduction, sans explications et sans balises Markdown (pas de ```json ... ```).

### RÈGLES STRICTES DE FORMATTAGE JSON (CRITIQUE)
- **AUCUNE SYNTAXE PHP DANS LE JSON** : N'utilise JAMAIS d'opérateurs d'association PHP `=>`, ni de tableaux PHP `['key' => 'val']`. 
  Tout doit être du JSON valide (ex : `{"key": "val"}`).
- **Structure des objets et tableaux** : Les tableaux associatifs PHP doivent TOUJOURS être traduits en objets JSON (`{"clé": "valeur"}`).
- **Encadrement strict des clés et valeurs string** : N'oublie jamais les guillemets doubles autour des clés JSON.
- **Strict RAW JSON Output** : Réponds EXCLUSIVEMENT par l'objet JSON brut. 
  N'inclus AUCUN bloc de code Markdown (PAS de ```json ... ```), AUCUN texte d'introduction ni d'explication.

### SCHÉMA JSON OBLIGATOIRE
{
  "targetClass": "Nom complet FQCN de la classe",
  "testType": "{$testTypeLabel}",
  "dependenciesToMock": [
    {
      "class": "FQCN\\De\\La\\Dependance",
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
                "param1": "valeur1",
                "param2": "valeur2",
                "expectedResult": "valeurAttendue"
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
            "param1": "valeur"
          },
          "mockExpectations": [
            {
              "dependency": "FQCN\\De\\La\\Dependance",
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
     * Rédige le prompt utilisateur contenant le code PHP à analyser.
     */
    private function buildUserPrompt(string $classCode, string $fqcn, ?string $methodName, string $type): string
    {
        $prompt = sprintf(
            "Voici le code source de la classe PHP à analyser (`%s`) pour générer une suite de tests de type **%s** :\n\n```php\n%s\n```",
            $fqcn,
            strtoupper($type),
            $classCode
        );

        if ($methodName) {
            $prompt .= sprintf(
                "\n\n ATTENTION : L'utilisateur souhaite cibler en priorité la méthode `%s()`. "
                . 'Concentre ton analyse sur cette méthode et ses interactions.',
                $methodName
            );
        }

        return $prompt;
    }
}
