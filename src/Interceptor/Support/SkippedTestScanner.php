<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class SkippedTestScanner
{
    public function __construct(private Parser $parser) {}

    /**
     * @return list<array{test: Node\Stmt\ClassMethod, statement: Node\Stmt\Expression, target: \ReflectionMethod}>
     */
    public function scan(string $code, \ReflectionClass $sut): array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        $finder = new NodeFinder();
        $hits = [];
        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $test) {
            foreach ($finder->findInstanceOf($test->stmts ?? [], Node\Stmt\Expression::class) as $statement) {
                $call = $statement->expr;
                if (!($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall)
                    || !$call->name instanceof Node\Identifier
                    || !\in_array($call->name->toString(), ['markTestSkipped', 'markTestIncomplete'], true)) {
                    continue;
                }
                $reason = $call->args[0]->value ?? null;
                if (!$reason instanceof Node\Scalar\String_) {
                    continue; // raison non littérale : on ne devine rien
                }
                if (null !== $target = $this->targetOf($reason->value, $sut)) {
                    $hits[] = ['test' => $test, 'statement' => $statement, 'target' => $target];
                }
            }
        }

        return $hits;
    }

    /**
     * La méthode du SUT qui est citée dans la raison (« Méthode processSingleDay est privée »), ou null.
     */
    private function targetOf(string $reason, \ReflectionClass $sut): ?\ReflectionMethod
    {
        preg_match_all('/\b[A-Za-z_]\w*\b/', $reason, $words);
        $methods = [];
        foreach (array_unique($words[0]) as $word) {
            if ($sut->hasMethod($word)) {
                $methods[] = $sut->getMethod($word);
            }
        }

        return 1 === \count($methods) ? $methods[0] : null; // aucune ou ambiguë : on ne touche pas
    }
}