<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 40)]
final readonly class FloatPrecisionPreFixer implements TestPreFixerInterface
{
    private const PATTERN = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\RFailed asserting that (?<actual>-?\d+(?:\.\d+)?(?:E[+-]?\d+)?) (?:is identical to|matches expected|is equal to) (?<expected>-?\d+(?:\.\d+)?(?:E[+-]?\d+)?)\.\R+\S+Test\.php:(?<line>\d+)/m';

    public function __construct(private Parser $parser)
    {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $m, PREG_SET_ORDER);

        $targets = [];
        foreach ($m as $e) {
            $diff = abs((float) $e['actual'] - (float) $e['expected']);
            $isFloat = 1 === preg_match('/[.eE]/', $e['actual'] . $e['expected']);
            if ($isFloat && $diff <= 1.0E-9 * max(1.0, abs((float) $e['expected'])) && $diff <= 1.0E-6) {
                $targets[] = [$e['test'], (int) $e['line']]; // pure imprécision de représentation
            }
        }
        if ([] === $targets) {
            return null;
        }

        try {
            $stmts = $this->parser->parse($testCode) ?? [];
        } catch (Error) {
            return null;
        }

        $finder = new NodeFinder();
        $edits = []; // position de début => [longueur, texte]
        foreach ($targets as [$test, $line]) {
            $method = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $test);
            $call = $finder->findFirst($method?->stmts ?? [], static fn (Node $n): bool => ($n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\MethodCall)
                && $n->name instanceof Node\Identifier
                && \in_array($n->name->toString(), ['assertSame', 'assertEquals'], true)
                && \count($n->args) >= 2
                && $n->getStartLine() <= $line && $line <= $n->getEndLine());
            if (null === $call || $call->args[0]->unpack || $call->args[1]->unpack) {
                continue;
            }

            $name = $call->name;
            $edits[$name->getStartFilePos()] = [$name->getEndFilePos() - $name->getStartFilePos() + 1, 'assertEqualsWithDelta'];
            $edits[$call->args[1]->getEndFilePos() + 1] = [0, ', 0.000001']; // le message éventuel devient le 4e argument
        }
        if ([] === $edits) {
            return null;
        }

        krsort($edits); // de la fin vers le début : les positions restent valides
        foreach ($edits as $start => [$length, $text]) {
            $testCode = substr_replace($testCode, $text, $start, $length);
        }

        return $testCode;
    }
}