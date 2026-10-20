<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Mika\TestGeneratorBundle\Parser\TypeErrorInfo;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

final class ArgumentOriginResolver
{
    private Standard $printer;

    public function __construct(
        private readonly Parser $parser, private readonly TestSnapshotStore $snapshots)
    {
        $this->printer = new Standard();
    }

    /** @return array{enclosing: string, call: string, arg: Node\Expr}|null */
    public function resolve(TypeErrorInfo $error): ?array
    {
        if (!$error->file || !$error->line) {
            return null;
        }

        $path = is_file($error->file) ? $error->file : $this->snapshots->find($error->file);
        if (null === $path) {
            return null;
        }

        $stmts = $this->parser->parse((string) file_get_contents($path));
        $finder = new NodeFinder();
        $covers = fn(Node $node): bool => $node->getStartLine() <= $error->line && $error->line <= $node->getEndLine();

        $method = $finder->findFirst($stmts, fn(Node $node) =>
            $node instanceof Node\Stmt\ClassMethod && $covers($node));

        $call = $finder->findFirst($method?->stmts ?? [], fn(Node $node) =>
            ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === $error->targetMethod
            && $covers($node));

        if (!$call) {
            return null;
        }

        foreach ($call->args as $i => $a) {
            $named = $a->name?->toString() === $error->param;
            $positional = $a->name === null && $i === $error->position - 1;
            if ($named || $positional) {
                return [
                    'enclosing' => $method->name->toString(),
                    'call'      => $this->printer->prettyPrintExpr($call),
                    'arg'       => $a->value,
                ];
            }
        }
        return null;
    }
}
