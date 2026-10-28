<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

final class EntityApi
{
    /**
     * Comment un test peut poser la propriété $key sur un objet de $class.
     *
     * @return array{via: string, type: ?string}|null null : ni argument de constructeur ni mutateur public
     */
    public static function settable(string $class, string $key): ?array
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $key) {
                return ['via' => 'constructor', 'type' => self::scalar($parameter->getType())];
            }
        }

        $base = ucfirst($key);
        foreach (['set' . $base, 'add' . $base, 'setIs' . $base] as $name) {
            if ($reflection->hasMethod($name)) {
                $method = $reflection->getMethod($name);
                if ($method->isPublic() && !$method->isStatic() && 1 === $method->getNumberOfParameters()) {
                    return ['via' => 'mutator', 'type' => self::scalar($method->getParameters()[0]->getType())];
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> propriétés qu'un test peut poser (arguments de constructeur et mutateurs)
     */
    public static function settableKeys(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $reflection = new \ReflectionClass($class);

        $keys = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $reflection->getConstructor()?->getParameters() ?? [],
        );
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (1 === preg_match('/^(?:set|add)([A-Z]\w*)$/', $method->getName(), $m) && 1 === $method->getNumberOfParameters() && !$method->isStatic()) {
                $keys[] = lcfirst($m[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Aligne une valeur JSON sur le type scalaire de l'argument : `100` devient `"100"` pour `?string`.
     */
    public static function coerce(mixed $value, ?string $type): mixed
    {
        return match (true) {
            'string' === $type && (\is_int($value) || \is_float($value)) => (string) $value,
            'float' === $type && \is_int($value) => (float) $value,
            'int' === $type && \is_string($value) && 1 === preg_match('/^-?\d+$/', $value) => (int) $value,
            default => $value,
        };
    }

    private static function scalar(?\ReflectionType $type): ?string
    {
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return null;
        }

        return \in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array'], true) ? $type->getName() : null;
    }
}