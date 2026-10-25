<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;


/** @noinspection PhpUnused */
#[AsTaggedItem(priority: 40)]
final readonly class FloatPrecisionPreFixer implements TestPreFixerInterface
{
    // language=RegExp
    private const PATTERN = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\RFailed asserting that (?<actual>-?\d+(?:\.\d+)?(?:E[+-]?\d+)?) (?:is identical to|matches expected|is equal to) (?<expected>-?\d+(?:\.\d+)?(?:E[+-]?\d+)?)\.\R+\S+Test\.php:(?<line>\d+)/m';

    public function __construct(private Parser $parser)
    {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $m, PREG_SET_ORDER);

        $targets = []; // [test, ligne, tout le test ?]
        foreach ($m as $e) {
            $diff = abs((float) $e['actual'] - (float) $e['expected']);
            $isFloat = 1 === preg_match('/[.eE]/', $e['actual'] . $e['expected']);
            if ($isFloat && $diff <= 1.0E-9 * max(1.0, abs((float) $e['expected'])) && $diff <= 1.0E-6) {
                // `0` contre `0.0` : même valeur, type différent, donc toutes les assertSame à littéral flottant du test sont concernées
                $targets[] = [$e['test'], (int) $e['line'], 0.0 === $diff && $e['actual'] !== $e['expected']];
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

        $isFloatLiteral = static fn (Node $v): bool => $v instanceof Node\Scalar\Float_
            || ($v instanceof Node\Expr\UnaryMinus && $v->expr instanceof Node\Scalar\Float_);
        $isAssertion = static fn (Node $n): bool => ($n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\MethodCall)
            && $n->name instanceof Node\Identifier
            && \in_array($n->name->toString(), ['assertSame', 'assertEquals'], true)
            && \count($n->args) >= 2
            && $n->args[0] instanceof Node\Arg && !$n->args[0]->unpack
            && $n->args[1] instanceof Node\Arg && !$n->args[1]->unpack;

        $finder = new NodeFinder();
        $edits = []; // position de début => [longueur, texte]
        foreach ($targets as [$test, $line, $wholeTest]) {
            $method = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $test);
            $calls = $finder->find($method?->stmts ?? [], static fn (Node $n): bool => $isAssertion($n)
                && ($wholeTest ? $isFloatLiteral($n->args[0]->value) : ($n->getStartLine() <= $line && $line <= $n->getEndLine())));

            foreach ($calls as $call) {
                $edits[$call->name->getStartFilePos()] = [$call->name->getEndFilePos() - $call->name->getStartFilePos() + 1, 'assertEqualsWithDelta'];
                $edits[$call->args[1]->getEndFilePos() + 1] = [0, ', 0.000001']; // le message éventuel devient le 4e argument
            }
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