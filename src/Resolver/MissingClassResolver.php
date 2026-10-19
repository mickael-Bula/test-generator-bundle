<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Mika\TestGeneratorBundle\Interceptor\Support\RepoMapProvider;
use Psr\Cache\InvalidArgumentException;

final readonly class MissingClassResolver
{
    private const PATTERN = '/(?:Class or interface|Class|Interface|Trait|Enum) "(?<fqcn>[^"]+)"(?: does not exist| not found)/';

    public function __construct(private RepoMapProvider $maps) {}

    /** @return list<string> */
    public function missingFrom(string $errorOutput): array
    {
        preg_match_all(self::PATTERN, $errorOutput, $m);

        return array_values(array_unique($m['fqcn']));
    }

    /** @return array{0: string, 1: list<string>} [source, FQCN candidats] */
    public function resolve(string $short, ?string $sut): array
    {
        $strategies = [
            'type déclaré dans la signature du SUT' => fn (): array => $this->fromSutSignatures($short, $sut),
            'import `use` du fichier du SUT' => fn (): array => $this->fromSutImports($short, $sut),
            'classe de src/' => fn (): array => $this->fromRepoMap($short, $sut),
        ];

        foreach ($strategies as $source => $strategy) {
            if ([] !== $found = $strategy()) {
                return [$source, $found];
            }
        }

        return ['', []];
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public function similar(string $short): array
    {
        $scores = [];
        foreach (array_keys($this->maps->get()->classes) as $fqcn) {
            similar_text(strtolower($short), strtolower($this->shortName($fqcn)), $percent);
            if ($percent >= 75.0) {
                $scores[$fqcn] = $percent;
            }
        }
        arsort($scores);

        return \array_slice(array_keys($scores), 0, 3);
    }

    /** @return list<string> */
    private function fromSutSignatures(string $short, ?string $sut): array
    {
        if (null === $sut || !class_exists($sut)) {
            return [];
        }

        $found = [];
        foreach ((new \ReflectionClass($sut))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $types = array_map(static fn (\ReflectionParameter $p): ?\ReflectionType => $p->getType(), $method->getParameters());
            $types[] = $method->getReturnType();

            foreach ($types as $type) {
                foreach ($this->namedTypes($type) as $named) {
                    $fqcn = ltrim($named->getName(), '\\');
                    if (!$named->isBuiltin() && $this->shortName($fqcn) === $short) {
                        $found[] = $fqcn;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private function fromSutImports(string $short, ?string $sut): array
    {
        if (null === $sut || !class_exists($sut) || !($file = (new \ReflectionClass($sut))->getFileName())) {
            return [];
        }

        preg_match_all('/^use\s+(?<fqcn>[\w\\\\]+)(?:\s+as\s+\w+)?\s*;/m', (string) file_get_contents($file), $m);

        return array_values(array_filter(
            array_unique($m['fqcn']),
            fn (string $fqcn): bool => $this->shortName($fqcn) === $short,
        ));
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    private function fromRepoMap(string $short, ?string $sut): array
    {
        $map = $this->maps->get();
        $neighborhood = null !== $sut ? $map->expand([$sut], 2) : [];

        $matches = array_filter(
            array_keys($map->classes),
            fn (string $fqcn): bool => $this->shortName($fqcn) === $short,
        );

        // Voisinage du SUT d'abord : c'est presque toujours la bonne classe.
        usort($matches, static fn (string $a, string $b): int => isset($neighborhood[$b]) <=> isset($neighborhood[$a]));

        return $matches;
    }

    /**
     * @return list<\ReflectionNamedType>
     */
    private function namedTypes(?\ReflectionType $type): array
    {
        return match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType,
                $type instanceof \ReflectionIntersectionType => array_merge(...array_map($this->namedTypes(...), $type->getTypes())),
            default => [],
        };
    }

    private function shortName(string $fqcn): string
    {
        return basename(str_replace('\\', '/', $fqcn));
    }
}
