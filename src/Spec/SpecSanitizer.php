<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Spec;

use Mika\TestGeneratorBundle\Reflection\EntityApi;
use Mika\TestGeneratorBundle\Reflection\EnumCases;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use Psr\Log\LoggerInterface;

final readonly class SpecSanitizer
{
    public function __construct(private Parser $parser, private ?LoggerInterface $logger = null)
    {
    }

    public function sanitize(string $spec, string $sutFqcn): string
    {
        if (!class_exists($sutFqcn)) {
            return $spec;
        }
        $rc = new \ReflectionClass($sutFqcn);

        $kept = [];
        foreach (preg_split('/^---[ \t]*$/m', $spec) ?: [] as $chunk) {
            if (1 === preg_match('/^## Méthode `(?<name>\w+)\(\)`/m', $chunk, $m)) {
                if (!$rc->hasMethod($m['name']) || !$rc->getMethod($m['name'])->isPublic()) {
                    continue; // privée, protégée ou inexistante : aucun test direct possible
                }
                $chunk = $this->dropSutMocks($chunk, $sutFqcn);
                $chunk = $this->normalizeEnumInputs($chunk, $rc->getMethod($m['name']));
                $chunk = $this->normalizeEntityInputs($chunk, $rc->getMethod($m['name']));
                if (null === $chunk) {
                    continue; // plus aucun cas exploitable pour cette méthode
                }
            }
            $kept[] = trim($chunk);
        }
        $kept[] = $this->coverageNote($rc);

        return implode("\n\n---\n\n", array_filter($kept)) . "\n";
    }

    private function dropSutMocks(string $chunk, string $sut): string
    {
        // le SUT n'est jamais un mock
        $chunk = preg_replace('/^[ \t]*- `' . preg_quote($sut, '/') . '::\w+\(\)`.*\R?/m', '', $chunk) ?? $chunk;

        // En-tête « Attentes Mocks » resté sans aucune ligne
        return preg_replace('/^\* \*\*Attentes Mocks\*\* :\R(?=\*|#|[ \t]*\R|\s*\z)/m', '', $chunk) ?? $chunk;
    }

    private function coverageNote(\ReflectionClass $rc): string
    {
        $lines = [];
        foreach ($this->indirectCoverage($rc) as $public => $others) {
            $lines[] = sprintf(
                '- `%s()` exerce aussi : %s',
                $public,
                implode(', ', array_map(static fn (string $o): string => "`$o()`", $others)),
            );
        }
        if ([] === $lines) {
            return '';
        }

        return "## Méthodes non publiques : couvertes via les méthodes publiques\n\n"
            . "Elles ne sont pas appelables depuis un test et n'ont donc aucune section dédiée. "
            . "Pour couvrir leurs branches, construis les scénarios des méthodes publiques qui y mènent "
            . "(données d'entrée, retours des mocks). Ne mocke JAMAIS une méthode de la classe testée.\n\n"
            . implode("\n", $lines);
    }

    /**
     * @return array<string, list<string>> méthode publique => méthodes non publiques qu'elle exerce, directement ou non
     */
    private function indirectCoverage(\ReflectionClass $rc): array
    {
        $file = $rc->getFileName();
        if (false === $file) {
            return [];
        }

        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }

        $finder = new NodeFinder();
        $calls = []; // méthode => méthodes de $this qu'elle appelle
        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $method) {
            $calls[$method->name->toString()] = array_values(array_unique(array_map(
                static fn (Node\Expr\MethodCall $c): string => $c->name->toString(),
                $finder->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall
                    && $n->var instanceof Node\Expr\Variable
                    && 'this' === $n->var->name
                    && $n->name instanceof Node\Identifier),
            )));
        }

        $coverage = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $public) {
            $seen = [];
            $stack = $calls[$public->getName()] ?? [];
            while ([] !== $stack) {
                $name = array_pop($stack);
                if (isset($seen[$name]) || !$rc->hasMethod($name) || $rc->getMethod($name)->isPublic()) {
                    continue; // on ne descend que dans les méthodes non publiques
                }
                $seen[$name] = true;
                array_push($stack, ...($calls[$name] ?? []));
            }
            if ([] !== $seen) {
                $coverage[$public->getName()] = array_keys($seen);
            }
        }

        return $coverage;
    }

    /**
     * @return string|null le bloc normalisé, ou null s'il ne reste aucun cas
     */
    private function normalizeEnumInputs(string $chunk, \ReflectionMethod $method): ?string
    {
        $enums = []; // paramètre => [enum, nullable]
        foreach ($method->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && enum_exists($type->getName())) {
                $enums[$p->getName()] = [$type->getName(), $type->allowsNull()];
            }
        }
        if ([] === $enums) {
            return $chunk;
        }

        $parts = preg_split('/(?=^#### )/m', $chunk) ?: [$chunk];
        $kept = [array_shift($parts)]; // en-tête de la section
        $cases = 0;

        foreach ($parts as $block) {
            $isError = str_contains($block, '**Type** : `ERROR`') || str_contains($block, '**Exception attendue**');
            $dropped = false;

            $block = (string) preg_replace_callback(
                '/`\$(?<name>\w+)` = `(?<value>[^`]*)`/',
                function (array $m) use ($enums, $isError, $method, &$dropped): string {
                    if (!isset($enums[$m['name']])) {
                        return $m[0];
                    }
                    [$enum, $nullable] = $enums[$m['name']];
                    if ($nullable && 'null' === strtolower(trim($m['value']))) {
                        return $m[0];
                    }

                    $case = EnumCases::resolve($enum, $m['value']);
                    if (null === $case) {
                        // Cas d'erreur : PHP refuserait l'appel avant d'entrer dans la méthode, le scénario ne teste que PHP
                        $first = array_key_first(EnumCases::of($enum)['cases'] ?? []);
                        if ($isError || null === $first) {
                            $dropped = true;
                            $this->logger?->info(
                                sprintf('Spec : cas écarté, `%s` n\'est pas un cas de %s (%s::%s).',
                                    $m['value'],
                                    $enum,
                                    $method->class,
                                    $method->name,
                                )
                            );

                            return $m[0];
                        }
                        // Scénario nominal : l'appel est valable, seule la valeur d'enum était inventée
                        $this->logger?->info(
                            sprintf(
                                'Spec : `%s` remplacé par %s::%s (%s::%s).',
                                $m['value'],
                                $enum,
                                $first,
                                $method->class,
                                $method->name,
                            )
                        );
                        $case = (string) $first;
                    }

                    return sprintf('`$%s` = `\%s::%s`', $m['name'], $enum, $case);
                },
                $block,
            );

            if (!$dropped) {
                $kept[] = $block;
                ++$cases;
            }
        }

        return 0 === $cases ? null : implode('', $kept);
    }

    /**
     * Entrées d'entités : retire ce que le test ne peut pas poser, aligne les types scalaires sur ceux des mutateurs.
     * @throws \ReflectionException
     */
    private function normalizeEntityInputs(string $chunk, \ReflectionMethod $method): string
    {
        $entities = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName()) || enum_exists($type->getName())) {
                continue;
            }
            $reflection = new \ReflectionClass($type->getName());
            if (!$reflection->isInternal() && $reflection->isInstantiable()) {
                $entities[$parameter->getName()] = $type->getName();
            }
        }
        if ([] === $entities) {
            return $chunk;
        }

        return (string) preg_replace_callback(
            '/^(?<head>\* \*\*Entrées\*\* : )(?<line>.+)$/m',
            function (array $line) use ($entities, $method): string {
                $removed = [];
                $rewritten = (string) preg_replace_callback(
                    '/`\$(?<name>\w+)` = `(?<value>[^`]*)`/',
                    function (array $m) use ($entities, &$removed, $method): string {
                        $data = isset($entities[$m['name']]) && str_starts_with(ltrim($m['value']), '{') ? json_decode($m['value'], true) : null;
                        if (!\is_array($data)) {
                            return $m[0];
                        }

                        $kept = [];
                        foreach ($data as $key => $value) {
                            $api = EntityApi::settable($entities[$m['name']], (string) $key);
                            if (null === $api) {
                                $removed[$m['name']][] = (string) $key;
                                $this->logger?->info(sprintf(
                                    'Spec : propriété `%s` retirée de $%s (%s::%s) : aucun mutateur ni argument de constructeur.',
                                    $key, $m['name'], $method->class, $method->name
                                ));
                                continue;
                            }
                            $kept[$key] = EntityApi::coerce($value, $api['type']);
                        }

                        return sprintf(
                            '`$%s` = `%s`',
                            $m['name'], [] === $kept ? '[]' : json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        );
                    },
                    $line['line'],
                );

                $note = [];
                foreach ($removed as $name => $keys) {
                    $note[] = sprintf('`$%s` : %s', $name, implode(', ', $keys));
                }

                return $line['head']
                    . $rewritten
                    . ([] === $note ?
                        '' :
                        "\n* **Propriétés retirées** (aucun mutateur ni argument de constructeur : un test ne peut pas les poser) : " . implode(' ; ', $note)
                    );
            },
            $chunk,
        );
    }
}