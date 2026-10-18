<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

final class DocTypeResolver
{
    // Un type PHPDoc : mots, génériques `<…>`, formes `{…}` et groupes `(…)`, espaces internes compris
    private const TYPE = '(?<t>(?:[^\s<{(]+|<[^>]*>|\{[^}]*\}|\([^)]*\))+)';

    /** @var array<string, array<string, string>> */
    private array $usesCache = [];

    /**
     * Type PHPDoc d'un paramètre, noms de classes en FQCN : `iterable<Position>` => `iterable<\App\Entity\Position>`.
     */
    public function paramType(\ReflectionMethod $method, string $param): ?string
    {
        $doc = $method->getDocComment() ?: '';

        return 1 === preg_match('/@param\s+' . self::TYPE . '\s+[&.]*\$' . preg_quote($param, '/') . '\b/', $doc, $m)
            ? $this->qualify($m['t'], $method->getDeclaringClass())
            : null;
    }

    public function returnType(\ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment() ?: '';

        return 1 === preg_match('/@return\s+' . self::TYPE . '/', $doc, $m)
            ? $this->qualify($m['t'], $method->getDeclaringClass())
            : null;
    }

    // Lignes `@param type $nom` (toutes, ou seulement $only) et `@return type`, types qualifiés.
    /**
     * @return list<string>
     */
    public function lines(\ReflectionMethod $method, ?string $only = null): array
    {
        $doc = $method->getDocComment() ?: '';
        $class = $method->getDeclaringClass();
        $lines = [];

        if (preg_match_all('/@param\s+' . self::TYPE . '\s+[&.]*\$(?<n>\w+)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (null === $only || $only === $x['n']) {
                    $lines[] = sprintf('@param %s $%s', $this->qualify($x['t'], $class), $x['n']);
                }
            }
        }
        if (null === $only && null !== $return = $this->returnType($method)) {
            $lines[] = '@return ' . $return;
        }

        return $lines;
    }

    /**
     * Un nom court se résout par les `use` de la classe, sinon dans son namespace ; `\Foo` reste tel quel.
     */
    public function qualify(string $docType, \ReflectionClass $declaring): string
    {
        $uses = $this->usesOf($declaring);
        $namespace = $declaring->getNamespaceName();

        return (string) preg_replace_callback(
            '/(?<![\\\\\w$])[A-Z]\w*/',
            static fn (array $n): string => '\\' . ($uses[$n[0]] ?? ('' === $namespace ? '' : $namespace . '\\') . $n[0]),
            $docType,
        );
    }

    /**
     * @return array<string, string> nom court (ou alias) => FQCN, `use A\{B, C}` groupés compris
     */
    private function usesOf(\ReflectionClass $class): array
    {
        if (isset($this->usesCache[$class->getName()])) {
            return $this->usesCache[$class->getName()];
        }

        $file = $class->getFileName();
        $code = false !== $file ? (string) file_get_contents($file) : '';
        $map = [];

        preg_match_all('/^use\s+(?<name>[\w\\\\]+)(?:\s+as\s+(?<alias>\w+))?\s*;/m', $code, $simple, PREG_SET_ORDER);
        foreach ($simple as $u) {
            $map[($u['alias'] ?? '') ?: basename(str_replace('\\', '/', $u['name']))] = $u['name'];
        }

        preg_match_all('/^use\s+(?<prefix>[\w\\\\]+)\\\\\{(?<items>[^}]+)\}\s*;/m', $code, $grouped, PREG_SET_ORDER);
        foreach ($grouped as $g) {
            foreach (explode(',', $g['items']) as $item) {
                if (1 === preg_match('/^\s*(?<name>[\w\\\\]+)(?:\s+as\s+(?<alias>\w+))?\s*$/', $item, $i)) {
                    $map[($i['alias'] ?? '') ?: basename(str_replace('\\', '/', $i['name']))] = $g['prefix'] . '\\' . $i['name'];
                }
            }
        }

        return $this->usesCache[$class->getName()] = $map;
    }
}