# Note d'Architecture : Désérialisation des Spécifications via `SpecSerializerFactory`

## Context & Objectif

Lorsque la réponse brute au format JSON est renvoyée par le LLM, 
elle doit être convertie (dénormalisée) en un graphe d'objets DTO typés 
(ex : `SpecResultDto`, qui contient un tableau de `MethodSpecDto`, qui eux-mêmes contiennent des `TestCaseDto`, etc.).

Étant donné que le bundle **TestGeneratorBundle** peut être exécuté dans des applications hôtes 
qui n'injectent pas forcément de `SerializerInterface` personnalisé dans le conteneur de services (DI), 
la classe `SpecSerializerFactory` instancie et configure manuellement un composant `Serializer` autonome, 
optimisé pour gérer les objets imbriqués et la lecture des types PHP via les attributs et la PHPDoc.

## Anatomie du Serializer personnalisé (`SpecSerializerFactory`)

La Factory construit un pipeline de dénormalisation composé de 4 briques fondamentales :

```php
<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Serializer;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Instanciation manuelle d'un Serializer compatible avec les attributs PHP et les collections génériques PHPDoc.
 * Utilisé pour garantir la désérialisation des DTOs même si l'application hôte n'a pas de Serializer configuré.
 */
class SpecSerializerFactory
{
    public static function create(): SerializerInterface&DenormalizerInterface
    {
        // 1. Détection des métadonnées (Attributs PHP #[SerializedName], etc.)
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $metadataAwareNameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        // 2. Extraction du type des propriétés via PHPDoc et Reflection
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [
                new PhpDocExtractor(),
                new ReflectionExtractor(),
            ]
        );

        // 3. Principal Normalizer d'objets configuré avec le NameConverter et le PropertyInfoExtractor
        $normalizer = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: $metadataAwareNameConverter,
            propertyTypeExtractor: $propertyTypeExtractor
        );

        // 4. Assemblage du Serializer (ObjectNormalizer + ArrayDenormalizer + JsonEncoder)
        return new Serializer(
            [$normalizer, new ArrayDenormalizer()],
            [new JsonEncoder()]
        );
    }
}
```

### Explication détaillée des composants

1. `ClassMetadataFactory` & `MetadataAwareNameConverter`
   - **Rôle :** Prendre en compte le mapping personnalisé des propriétés si des attributs PHP 
     (comme `#[SerializedName('custom_name')]`) sont utilisés sur les DTOs.
   - **Fonctionnement :** L'`AttributeLoader` lit les attributs des classes DTO et transmet ces métadonnées 
     au `NameConverter` afin qu'il fasse le pont entre la clé JSON et le nom de la propriété PHP.
2. `PropertyInfoExtractor` (`PhpDocExtractor` + `ReflectionExtractor`)
   - **Rôle :** Inspecter la structure des DTOs pour fournir au `Serializer` le type exact de chaque propriété.
   - **Pourquoi réunir les deux extracteurs ?**
      - `ReflectionExtractor` : Lit le typage PHP natif (ex : `public string $name`).
      - `PhpDocExtractor` : Lit le contenu des annotations PHPDoc (ex : `@var array<int, MethodSpecDto>`).
3. `ObjectNormalizer`
   - **Rôle :** Assurer la transformation bidirectionnelle entre un tableau associatif brut et une instance d'objet PHP 
     (instanciation du DTO, affectation des propriétés via les setters ou le constructeur).
   - En lui injectant le `propertyTypeExtractor`, on lui donne la capacité d'interroger la PHPDoc avant de remplir une propriété.
4. `ArrayDenormalizer`
   - **Rôle :** Dénormaliser les listes/collections d'objets (`DTO[]`).
   - Sans cet élément dans le tableau des normalizers (`[$normalizer, new ArrayDenormalizer()]`), 
     le `Serializer` ne saura pas boucler sur un tableau JSON pour instancier chaque sous-élément sous forme de DTO.

## Problématique des collections d'objets imbriqués (Dénormalisation des collections)

### Le problème

En PHP, les types scalaires et les classes sont fortement typés au niveau des propriétés 
(`public string $title`, `public MethodSpecDto $method`), 
mais les collections restent déclarées comme de simples tableaux PHP (`public array $methods`).

Par défaut, sans configuration spécifique :
1. Le `ReflectionExtractor` voit uniquement le type nativement déclaré : `array`.
2. Symfony `Serializer` dénormalise donc le sous-tableau JSON en un tableau associatif PHP standard, au lieu d'instancier des objets DTO.
3. Résultat lors de l'exécution du code :
   `Fatal Error: Call to a member function getName() on array.`

### La solution

Pour que le `Serializer` convertisse automatiquement les sous-tableaux JSON en collections d'objets DTO, 
trois éléments indissociables doivent être présents :

#### A. Les annotations PHPDoc sur les DTOs

Chaque propriété représentant un tableau d'objets dans vos DTOs doit indiquer explicitement le type générique dans sa PHPDoc :

```php
namespace Mika\TestGeneratorBundle\Dto;

class SpecResultDto
{
    /**
     * @param array<int, MethodSpecDto> $methods
     */
    public function __construct(
        public string $targetClass,
        public array $methods = [],
    ) {}
}
```

#### B. La présence du `PhpDocExtractor`

Le `PhpDocExtractor` lit le tag `@param array<int, MethodSpecDto>` 
et informe le `Serializer` que le tableau `$methods` contient des instances de `MethodSpecDto`.

>**Dépendance système :** *Nécessite la présence des packages `symfony/property-info` 
> et `phpdocumentor/reflection-docblock` dans le composer.json du bundle.*

#### C. Le `ArrayDenormalizer` dans la pile du `Serializer`

Lorsque le `Serializer` voit que la propriété `$methods` attend une collection de `MethodSpecDto`, 
il passe le relais au `ArrayDenormalizer`. 
Ce dernier itère sur le tableau JSON et appelle l'`ObjectNormalizer` sur chaque item pour créer les instances individuelles.

## Utilisation recommandée dans les Clients LLM

Pour instancier le `Serializer` dans un client LLM (ex : `SymfonyAiClient` ou `OllamaClient`), il suffit d'invoquer la Factory :

```php
namespace Mika\TestGeneratorBundle\Llm;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;
use Mika\TestGeneratorBundle\Serializer\SpecSerializerFactory;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class SymfonyAiClient implements LlmClientInterface
{
    private SerializerInterface&DenormalizerInterface $serializer;

    public function __construct(
        ?SerializerInterface $serializer = null,
    ) {
        // Utilise le Serializer injecté par DI s'il existe, sinon utilise la Factory
        $this->serializer = $serializer ?? SpecSerializerFactory::create();
    }

    public function callForSpec(array $messages, string $model): SpecResultDto
    {
        // ... appel au LLM pour récupérer la réponse JSON $rawJson ...

        /** @var SpecResultDto $dto */
        $dto = $this->serializer->deserialize(
            $rawJson,
            SpecResultDto::class,
            'json'
        );

        return $dto;
    }
}
```