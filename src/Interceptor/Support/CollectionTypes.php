<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final class CollectionTypes
{
    /**
     * Premier type non nul d'une déclaration (`?array` => `array`, `array|null` => `array`).
     */
    public static function main(string $expected): string
    {
        foreach (explode('|', $expected) as $part) {
            $part = ltrim(trim($part), '?\\');
            if ('' !== $part && 'null' !== strtolower($part)) {
                return $part;
            }
        }

        return '';
    }

    /**
     * Vrai si TOUS les types non nuls sont des collections : 'array', 'iterable', 'Collection', tout 'Traversable'.
     */
    public static function isCollectionLike(string $expected): bool
    {
        $parts = array_filter(
            array_map(static fn (string $p): string => ltrim(trim($p), '?\\'), explode('|', $expected)),
            static fn (string $p): bool => '' !== $p && 'null' !== strtolower($p),
        );
        foreach ($parts as $type) {
            $isCollection = \in_array(strtolower($type), ['array', 'iterable'], true)
                || ((class_exists($type) || interface_exists($type)) && is_a($type, \Traversable::class, true));
            if (!$isCollection) {
                return false;
            }
        }

        return [] !== $parts;
    }
}