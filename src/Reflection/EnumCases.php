<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

final class EnumCases
{
    /**
     * @return array{backing: ?string, cases: array<string, string|int|null>}|null null si $enum n'est pas un enum
     */
    public static function of(string $enum): ?array
    {
        $enum = ltrim($enum, '\\');
        if (!enum_exists($enum)) {
            return null;
        }

        $reflection = new \ReflectionEnum($enum);
        $cases = [];
        foreach ($reflection->getCases() as $case) {
            $cases[$case->getName()] = $case instanceof \ReflectionEnumBackedCase ? $case->getBackingValue() : null;
        }
        $type = $reflection->getBackingType();

        return ['backing' => $type instanceof \ReflectionNamedType ? $type->getName() : null, 'cases' => $cases];
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s_-]+/u', '', $name));
    }

    /**
     * Le cas dont le nom ou la valeur ne diffère de $invented que par la casse ou les séparateurs, s'il est UNIQUE.
     *
     * @param array<string, string|int|null> $cases
     */
    public static function exact(string $invented, array $cases): ?string
    {
        $target = self::normalize($invented);
        $hits = [];
        foreach ($cases as $name => $value) {
            if (self::normalize((string) $name) === $target || (\is_string($value) && self::normalize($value) === $target)) {
                $hits[(string) $name] = true;
            }
        }

        return 1 === \count($hits) ? (string) array_key_first($hits) : null;
    }

    /**
     * Jusqu'à trois cas proches (distance d'édition, inclusion) : un indice pour le LLM, jamais un remplacement automatique.
     *
     * @param array<string, string|int|null> $cases
     *
     * @return list<string>
     */
    public static function closest(string $invented, array $cases): array
    {
        $target = self::normalize($invented);
        $scores = [];
        foreach (array_keys($cases) as $name) {
            $candidate = self::normalize((string) $name);
            $distance = levenshtein($target, $candidate);
            if ($distance <= max(2, intdiv(\strlen($target), 3)) || str_contains($candidate, $target) || str_contains($target, $candidate)) {
                $scores[(string) $name] = $distance;
            }
        }
        asort($scores);

        return \array_slice(array_keys($scores), 0, 3);
    }

    /**
     * Le cas désigné par une valeur de spec : nom, valeur, `\Enum::CAS`, ou objet JSON à une clé (`{"key":"APPROVE"}`).
     * Null si aucun cas ne correspond.
     */
    public static function resolve(string $enum, string $raw): ?string
    {
        $catalog = self::of($enum);
        if (null === $catalog) {
            return null;
        }

        $value = trim($raw);
        $decoded = json_decode($value, true);
        if (\is_array($decoded) && 1 === \count($decoded) && \is_scalar(reset($decoded))) {
            $value = (string) reset($decoded);
        } elseif (\is_string($decoded)) {
            $value = $decoded;
        }
        $value = trim($value, " \t'\"`");
        if (str_contains($value, '::')) {
            $value = substr($value, (int) strrpos($value, '::') + 2);
        }

        if (isset($catalog['cases'][$value])) {
            return $value;
        }
        foreach ($catalog['cases'] as $name => $backing) {
            if (null !== $backing && (string) $backing === $value) {
                return $name;
            }
        }

        return self::exact($value, $catalog['cases']);
    }
}