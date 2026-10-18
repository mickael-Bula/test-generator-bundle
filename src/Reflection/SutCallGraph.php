<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class SutCallGraph
{
    public function __construct(private Parser $parser) {}

    /** @return list<string> méthodes publiques dont l'exécution atteint $target, directement ou par des méthodes non publiques */
    public function publicCallersOf(\ReflectionClass $sut, string $target): array
    {
        $file = $sut->getFileName();
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
                    && $n->var instanceof Node\Expr\Variable && 'this' === $n->var->name
                    && $n->name instanceof Node\Identifier),
            )));
        }

        $reaches = static function (string $from) use ($calls, $sut, $target): bool {
            $seen = [];
            $stack = [$from];
            while ([] !== $stack) {
                foreach ($calls[array_pop($stack)] ?? [] as $callee) {
                    if (0 === strcasecmp($callee, $target)) {
                        return true;
                    }
                    if (!isset($seen[$callee]) && $sut->hasMethod($callee) && !$sut->getMethod($callee)->isPublic()) {
                        $seen[$callee] = true;
                        $stack[] = $callee;
                    }
                }
            }

            return false;
        };

        $callers = [];
        foreach ($sut->getMethods(\ReflectionMethod::IS_PUBLIC) as $public) {
            if (!$public->isConstructor() && $reaches($public->getName())) {
                $callers[] = $public->getName();
            }
        }

        return $callers;
    }
}