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
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $metadataAwareNameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        // Définision de l'extracteur de types qui lit les PHPDoc (@param array<int, MethodSpecDto>)
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [
                new PhpDocExtractor(),
                new ReflectionExtractor(),
            ]
        );

        $normalizer = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: $metadataAwareNameConverter,
            propertyTypeExtractor: $propertyTypeExtractor
        );

        // Ajout du `ArrayDenormalizer` (DenormalizerInterface) pour dénormaliser les collections d'objets imbriqués dans SpecResultDto
        return new Serializer(
            [$normalizer, new ArrayDenormalizer()],
            [new JsonEncoder()]
        );
    }
}
