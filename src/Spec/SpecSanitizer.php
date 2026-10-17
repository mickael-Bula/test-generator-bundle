<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Spec;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class SpecSanitizer
{
    public function __construct(private Parser $parser)
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
}