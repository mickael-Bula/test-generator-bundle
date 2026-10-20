<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** @noinspection PhpUnused */
#[AsTaggedItem(priority: 60)]
final readonly class LiteralTypePreFixer implements TestPreFixerInterface
{
    // « Argument #1 ($x) must be of type ?string, int given, called in …Test.php on line 522 »
    // language=RegExp (commentaire ajouté pour éviter que l'IDE interprète la regex comme du HTML et signale des dépréciations).
    private const ARGUMENT = '/TypeError: (?<class>[\w\\\\]+)::(?<method>\w+)\(\): Argument #(?<pos>\d+) \(\$(?<param>\w+)\) must be of type (?<expected>[^,\r\n]+), (?:int|float|string) given, called in \S+Test\.php on line (?<line>\d+)/';

    // « Method f may not return value of type int, its declared return type is "float" », puis la ligne du test
    private const RETURN = '/IncompatibleReturnValueException: Method (?<method>\w+) may not return value of type (?:int|float|string), its declared return type is "(?<expected>[^"]+)"\R+\S+Test\.php:(?<line>\d+)/';

    public function __construct(private Parser $parser, private CallArgumentLocator $locator) {}

    public function fix(string $errorOutput, string $testCode): ?string
    {
        preg_match_all(self::ARGUMENT, $errorOutput, $arguments, PREG_SET_ORDER);
        preg_match_all(self::RETURN, $errorOutput, $returns, PREG_SET_ORDER);
        if ([] === $arguments && [] === $returns) {
            return null;
        }

        try {
            $stmts = $this->parser->parse($testCode) ?? [];
        } catch (Error) {
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $edits = []; // position de début => [position de fin, remplacement]
        foreach ($arguments as $e) {
            $this->plan($edits, $this->passedArgument($stmts, $e), $e['expected']);
        }
        foreach ($returns as $e) {
            $this->plan($edits, $this->returnedValue($stmts, $e), $e['expected']);
        }
        if ([] === $edits) {
            return null;
        }

        krsort($edits); // de la fin vers le début : les positions restent valides
        foreach ($edits as $start => [$end, $replacement]) {
            $testCode = substr_replace($testCode, $replacement, $start, $end - $start + 1);
        }

        return $testCode;
    }

    /**
     * @param array<int, array{0: int, 1: string}> $edits
     */
    private function plan(array &$edits, ?Node\Expr $value, string $expected): void
    {
        $text = null === $value ? null : ScalarLiteralCoercer::coerce($value, $expected);
        if (null !== $text) {
            $edits[$value->getStartFilePos()] = [$value->getEndFilePos(), $text];
        }
    }

    /**
     * L'expression passée à l'argument en cause, positionnel ou nommé (appel de méthode, statique ou `new`).
     */
    private function passedArgument(array $stmts, array $e): ?Node\Expr
    {
        return $this->locator->find($stmts, $e['class'], $e['method'], (int) $e['line'], (int) $e['pos'], $e['param']);
    }

    /**
     * L'argument de `willReturn()` dans la chaîne `->method('nom')->willReturn(…)`.
     */
    private function returnedValue(array $stmts, array $e): ?Node\Expr
    {
        $call = $this->locator->callAt($stmts, (int) $e['line'], fn (Node $n): bool => $n instanceof Node\Expr\MethodCall
            && $n->name instanceof Node\Identifier
            && 'willReturn' === $n->name->toString()
            && $this->stubs($n, $e['method']));

        $arg = $call?->args[0] ?? null;

        return $arg instanceof Node\Arg ? $arg->value : null;
    }

    private function stubs(Node\Expr\MethodCall $call, string $method): bool
    {
        for ($n = $call->var; $n instanceof Node\Expr\MethodCall; $n = $n->var) {
            $arg = $n->args[0] ?? null;
            if ($n->name instanceof Node\Identifier && 'method' === $n->name->toString()
                && $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_
                && 0 === strcasecmp($arg->value->value, $method)) {
                return true;
            }
        }

        return false;
    }
}