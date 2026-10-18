<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;

final class SutPropertyFinder
{
    /** Propriété du test typée avec le SUT. $stmts doit avoir traversé NameResolver. */
    public function find(array $stmts, string $sutClass): ?string
    {
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Stmt\Property::class) as $property) {
            if ($property->type instanceof Node\Name && $property->type->toString() === $sutClass) {
                return $property->props[0]->name->toString();
            }
        }

        return null;
    }
}