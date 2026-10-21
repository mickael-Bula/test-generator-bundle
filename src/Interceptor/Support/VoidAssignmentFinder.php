<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Extractor\MethodSource;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Applique une transformation sans perte :
 * `$x = (new X())->setA(1)->setB(2);` devient quatre instructions, une par appel.
 * Il ne s’applique que si la dernière méthode de la chaîne retourne void (déclaré, ou absent et sans return),
 * et si aucune méthode de la chaîne n’est inconnue ou ne retourne un autre type.
 * Tout autre cas est laissé tel quel.
 */
final class VoidAssignmentFinder
{
    /**
     * `$x = (new X(...))->setA(...)->setB(...);` où la dernière méthode ne retourne pas l'objet : `$x` vaut null.
     *
     * @param array<Node> $stmts statements déjà passés par NameResolver
     *
     * @return list<array{statement: Node\Stmt\Expression, variable: string, new: Node\Expr\New_, calls: list<Node\Expr\MethodCall>, class: string, method: string}>
     * @throws \ReflectionException
     */
    public function find(array $stmts): array
    {
        $found = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Stmt\Expression::class) as $statement) {
            $assign = $statement->expr;
            if (!$assign instanceof Node\Expr\Assign || !$assign->var instanceof Node\Expr\Variable || !\is_string($assign->var->name)) {
                continue;
            }

            $calls = [];
            $node = $assign->expr;
            while ($node instanceof Node\Expr\MethodCall) {
                $calls[] = $node;
                $node = $node->var;
            }
            if ([] === $calls || !$node instanceof Node\Expr\New_ || !$node->class instanceof Node\Name || !class_exists($node->class->toString())) {
                continue;
            }

            $class = new \ReflectionClass($node->class->toString());
            $calls = array_reverse($calls); // ordre d'exécution
            $states = array_map(fn (Node\Expr\MethodCall $c): ?string => $this->state($class, $c), $calls);
            if (\in_array(null, $states, true) || 'void' !== $states[array_key_last($states)]) {
                continue; // méthode inconnue, autre type de retour, ou dernière méthode fluide : `$x` est un objet valide
            }

            $found[] = [
                'statement' => $statement,
                'variable' => $assign->var->name,
                'new' => $node,
                'calls' => $calls,
                'class' => $class->getName(),
                'method' => $calls[array_key_last($calls)]->name->toString(),
            ];
        }

        return $found;
    }

    /**
     * Cas possibles : 'fluent' (retourne l'objet), 'void' (retourne null), ou null (inconnu ou autre type : on ne touche pas).
     */
    private function state(\ReflectionClass $class, Node\Expr\MethodCall $call): ?string
    {
        if (!$call->name instanceof Node\Identifier || !$class->hasMethod($call->name->toString())) {
            return null;
        }
        $method = $class->getMethod($call->name->toString());
        if (!$method->isPublic() || $method->isStatic()) {
            return null;
        }

        $type = $method->getReturnType();
        if ($type instanceof \ReflectionNamedType) {
            $name = strtolower($type->getName());

            return match (true) {
                'void' === $name => 'void',
                \in_array($name, ['static', 'self', strtolower($class->getName())], true) => 'fluent',
                default => null,
            };
        }
        if (null !== $type) {
            return null; // union, intersection
        }

        // Sans type de retour : fluide si le code contient `return $this`, inconnu s'il retourne autre chose, sinon void
        $source = MethodSource::full($method, 200);
        if (null === $source) {
            return null;
        }

        return match (true) {
            str_contains($source, 'return $this') => 'fluent',
            1 === preg_match('/\breturn\s+[^;\s]/', $source) => null,
            default => 'void',
        };
    }
}