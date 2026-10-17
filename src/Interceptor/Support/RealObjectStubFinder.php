<?php


declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

/**
 * Repère les objets créés avec `new` sur lesquels le test appelle `expects()` / `method()`.
 */
final readonly class RealObjectStubFinder
{
    public function __construct(private Parser $parser)
    {
    }

    /**
     * @return list<array{variable: string, class: string, methods: list<string>, new: Node\Expr\New_}>
     */
    public function find(string $code): array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return [];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $finder = new NodeFinder();
        $found = [];

        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $method) {
            $body = $method->stmts ?? [];

            // Méthodes stubbées par variable locale : `$var->[expects(...)->]method('nom')`
            $stubbed = [];
            foreach ($finder->findInstanceOf($body, Node\Expr\MethodCall::class) as $call) {
                if (!$call->name instanceof Node\Identifier || 'method' !== $call->name->toString()) {
                    continue;
                }
                $root = $call->var;
                while ($root instanceof Node\Expr\MethodCall) {
                    $root = $root->var;
                }
                $arg = $call->args[0]->value ?? null;
                if ($root instanceof Node\Expr\Variable && \is_string($root->name) && $arg instanceof Node\Scalar\String_) {
                    $stubbed[$root->name][] = $arg->value;
                }
            }

            // Parmi elles, celles assignées avec `new` : ce sont des objets réels
            foreach ($finder->findInstanceOf($body, Node\Expr\Assign::class) as $assign) {
                if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)
                    && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name
                    && isset($stubbed[$assign->var->name])) {
                    $found[] = [
                        'variable' => $assign->var->name,
                        'class' => $assign->expr->class->toString(),
                        'methods' => array_values(array_unique($stubbed[$assign->var->name])),
                        'new' => $assign->expr,
                    ];
                }
            }
        }

        return $found;
    }
}