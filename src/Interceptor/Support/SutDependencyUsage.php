<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class SutDependencyUsage
{
    public function __construct(private Parser $parser)
    {
    }

    /**
     * Méthodes publiques du SUT qui appellent $dependencyClass::$method(), directement ou via `$this->autre()`.
     * Suppose une dépendance reçue par le constructeur et portée par une propriété de même nom (promotion).
     *
     * @return list<string>
     * @throws \ReflectionException
     */
    public function publicMethodsReaching(\ReflectionClass $sut, string $dependencyClass, string $method): array
    {
        $file = $sut->getFileName();
        if (false === $file) {
            return [];
        }

        $props = [];
        foreach ($sut->getConstructor()?->getParameters() ?? [] as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), $dependencyClass, true)) {
                $props[$p->getName()] = true;
            }
        }
        if ([] === $props) {
            return [];
        }

        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }

        $finder = new NodeFinder();
        $reaching = []; // méthode => true
        $callees = [];  // méthode => méthodes de $this qu'elle appelle
        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $m) {
            $name = $m->name->toString();
            $callees[$name] = [];
            foreach ($finder->findInstanceOf($m->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                if (!$call->name instanceof Node\Identifier) {
                    continue;
                }
                $receiver = $call->var;
                if ($receiver instanceof Node\Expr\Variable && 'this' === $receiver->name) {
                    $callees[$name][] = $call->name->toString(); // $this->autre()
                } elseif (0 === strcasecmp($call->name->toString(), $method)
                    && $receiver instanceof Node\Expr\PropertyFetch
                    && $receiver->var instanceof Node\Expr\Variable && 'this' === $receiver->var->name
                    && $receiver->name instanceof Node\Identifier && isset($props[$receiver->name->toString()])) {
                    $reaching[$name] = true;                    // $this->dependance->méthode()
                }
            }
        }

        do { // propagation vers les appelants jusqu'à stabilité
            $changed = false;
            foreach ($callees as $name => $targets) {
                if (!isset($reaching[$name]) && [] !== array_intersect($targets, array_keys($reaching))) {
                    $reaching[$name] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        return array_values(array_filter(
            array_keys($reaching),
            static fn (string $n): bool => $sut->hasMethod($n) && $sut->getMethod($n)->isPublic(),
        ));
    }
}