<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

final class ObjectExporter
{
    private const MAX_DEPTH = 20;

    /**
     * Convertit un objet (propriétés privées et protégées comprises), un tableau ou un enum en structure sérialisable en JSON.
     */
    public static function toArray(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '…'; // références circulaires
        }

        return match (true) {
            \is_array($value) => array_map(static fn (mixed $v): mixed => self::toArray($v, $depth + 1), $value),
            $value instanceof \JsonSerializable => self::toArray($value->jsonSerialize(), $depth + 1),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            \is_object($value) => self::properties($value, $depth),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>|\stdClass un objet sans propriété reste un objet JSON (`{}`)
     */
    private static function properties(object $object, int $depth): array|\stdClass
    {
        $out = [];
        foreach ((new \ReflectionObject($object))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $out[$property->getName()] = $property->isInitialized($object)
                ? self::toArray($property->getValue($object), $depth + 1)
                : null; // propriété typée jamais affectée
        }

        return [] === $out ? new \stdClass() : $out;
    }
}