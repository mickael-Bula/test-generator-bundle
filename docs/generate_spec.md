## Génération d'un fichier de spécification par le LLM

## Procédure de génération

### 1. Demande au LLM

On demande au LLM d'analyser une classe PHP et de retourner exclusivement un objet JSON brut structuré selon le schéma suivant :
  
    ```json
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
    ```

    >*Règle de syntaxe stricte : 
    >Les valeurs passées dans `inputs` ou `providedValues` doivent toujours être du JSON valide 
    >(les tableaux PHP associatifs doivent être traduits sous forme d'objets `{"clé": "valeur"}`). 
    >L'utilisation de syntaxe PHP (`['key' => 'val']`) est strictement interdite par le prompt système.*

### 2. Dénormalisation et Rendu Markdown

1. La réponse JSON est désérialisée en un graphe de DTOs (`SpecResultDto`) via `SpecSerializerFactory`.
2. Le DTO est ensuite transformé en un fichier **Markdown** lisible à l'aide du service `SpecMarkdownRenderer`.
3. Le fichier **Markdown** est automatiquement enregistré dans le dossier du projet : `tests/Specs/<NomDeLaClasse>.md`.

## Utilisation du Fichier de Spécification

Le fichier **Markdown** généré sert de source de vérité (**Single Source of Truth**) pour la suite du workflow :
- Lors de la commande de génération de tests PHPUnit, 
  le contenu du fichier `tests/Specs/<NomDeLaClasse>.md` est automatiquement injecté dans le prompt du LLM générateur de code.
- Cela garantit que le code PHPUnit produit respecte scrupuleusement la *matrice de cas de test*, 
  les *Data Providers* et les *expectations* de **mocks** préalablement validés.

