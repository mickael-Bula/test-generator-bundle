<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;

final class CallArgumentLocator
{
    /**
     * L'expression passée à l'argument en cause, positionnel ou nommé (appel de méthode, statique ou `new`).
     *
     * @param array<Node> $stmts statements déjà passés par NameResolver
     */
    public function find(array $stmts, string $class, string $method, int $line, int $position, string $param): ?Node\Expr
    {
        $isConstructor = '__construct' === strtolower($method);

        /** @var Node\Expr\CallLike|null $call */
        $call = $this->callAt($stmts, $line, static function (Node $n) use ($class, $method, $isConstructor): bool {
            if ($isConstructor) {
                return $n instanceof Node\Expr\New_ && $n->class instanceof Node\Name && $n->class->toString() === $class;
            }

            return ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall)
                && $n->name instanceof Node\Identifier
                && 0 === strcasecmp($n->name->toString(), $method);
        });
        if (null === $call) {
            return null;
        }

        foreach ($call->args as $i => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                return null; // argument déplié : position indéterminée
            }
            if ($arg->name?->toString() === $param || (null === $arg->name && $i === $position - 1)) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * Le seul nœud correspondant à $match qui couvre la ligne.
     * En cas d'appels imbriqués, celui dont le nom est exactement sur la ligne.
     *
     * @param array<Node>        $stmts
     * @param callable(Node): bool $match
     */
    public function callAt(array $stmts, int $line, callable $match): ?Node
    {
        $found = (new NodeFinder())->find($stmts, static fn (Node $n): bool => $match($n)
            && $n->getStartLine() <= $line && $line <= $n->getEndLine());
        if (1 === \count($found)) {
            return $found[0];
        }

        $exact = array_values(array_filter($found, static fn (Node $n): bool => $line === ($n->name ?? $n)->getStartLine()));

        return 1 === \count($exact) ? $exact[0] : null;
    }
}