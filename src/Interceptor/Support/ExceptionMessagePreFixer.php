<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Reflection\ThrownMessages;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Corrige les messages levés par l'exception
 */
#[AsTaggedItem(priority: 55)]
final readonly class ExceptionMessagePreFixer implements TestPreFixerInterface
{
    // « Failed asserting that exception message 'réel' contains 'attendu'. » (les apostrophes du texte rendent le motif volontairement ancré sur la ligne)
    // language=RegExp
    private const FAILURE = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\n]*\nFailed asserting that exception message \'(?<actual>.*?)\' (?:contains|is) \'(?<expected>.*)\'\.?$/m';

    public function __construct(
        private Parser $parser,
        private CoveredClassReader $coveredClass,
        private ThrownMessages $thrown,
    ) {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        preg_match_all(self::FAILURE, str_replace("\r\n", "\n", $errorOutput), $failures, PREG_SET_ORDER);
        $class = $this->coveredClass->read($testCode);
        if ([] === $failures || null === $class || !class_exists($class)) {
            return null;
        }
        $sites = $this->thrown->of(new \ReflectionClass($class));
        if ([] === $sites) {
            return null;
        }

        try {
            $stmts = $this->parser->parse($testCode) ?? [];
        } catch (Error) {
            return null;
        }

        $finder = new NodeFinder();
        $edits = []; // début => [fin, littéral de remplacement]
        foreach ($failures as $f) {
            $stable = $this->stable($f['actual'], $sites);
            if (null === $stable) {
                continue; // le message réel ne vient d'aucun `throw` du SUT : on ne touche à rien
            }
            $test = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $f['test']);
            foreach ($finder->findInstanceOf($test?->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                $arg = $call->args[0] ?? null;
                if ($call->name instanceof Node\Identifier && 'expectExceptionMessage' === $call->name->toString()
                    && $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_ && $arg->value->value === $f['expected']) {
                    $edits[$arg->value->getStartFilePos()] = [$arg->value->getEndFilePos(), var_export($stable, true)];
                }
            }
        }
        if ([] === $edits) {
            return null;
        }

        krsort($edits); // de la fin vers le début : les positions restent valides
        foreach ($edits as $start => [$end, $literal]) {
            $testCode = substr_replace($testCode, $literal, $start, $end - $start + 1);
        }

        return $testCode;
    }

    /**
     * Fragment stable du message du `throw` correspondant (le message entier s'il n'a pas de partie fixe assez longue).
     */
    private function stable(string $actual, array $sites): ?string
    {
        foreach ($sites as $site) {
            if (1 === preg_match($site['regex'], $actual)) {
                return mb_strlen($site['stable']) >= 12 ? $site['stable'] : $actual;
            }
        }

        return null;
    }
}