<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Extractor\MethodSource;
use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

/** @noinspection PhpUnused */
final readonly class AssertionMismatchInterceptor implements ErrorInterceptorInterface
{
    private const FAILURE = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\R(?<msg>Failed asserting that [^\r\n]+)\R+\S+Test\.php:(?<line>\d+)/m';
    private const NUMBER = '/^Failed asserting that (?<actual>-?\d+(?:\.\d+)?(?:E[+-]?\d+)?) (?:is identical to|matches expected|is equal to) (?<expected>-?\d+(?:\.\d+)?(?:E[+-]?\d+)?)\.$/';
    private const BOOLEAN = '/^Failed asserting that (?<actual>true|false) is (?<expected>true|false)\.$/';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private Parser $parser,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return [] !== $this->failures($errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $failures = $this->failures($errorOutput);
        if ([] === $failures) {
            return null;
        }

        $located = $this->locator->locate($errorOutput);
        $stmts = $this->parse($located->testFile);
        $sut = null !== $located->sut && class_exists($located->sut) ? new \ReflectionClass($located->sut) : null;

        $md = "### Assertion en échec : le résultat du SUT diffère de l'attendu\n";
        foreach ($failures as $f) {
            $md .= $this->describe($f, $stmts, $sut);
        }

        return $md . $this->procedure($failures);
    }

    /** @return list<array{kind: string, test: string, line: int, actual: string, expected: string}> */
    private function failures(string $output): array
    {
        preg_match_all(self::FAILURE, $output, $m, PREG_SET_ORDER);
        $found = [];
        foreach ($m as $e) {
            foreach (['number' => self::NUMBER, 'boolean' => self::BOOLEAN] as $kind => $pattern) {
                if (1 === preg_match($pattern, $e['msg'], $v)) {
                    $found[] = ['kind' => $kind, 'test' => $e['test'], 'line' => (int) $e['line'], 'actual' => $v['actual'], 'expected' => $v['expected']];
                    break;
                }
            }
        }

        return $found;
    }

    private function describe(array $f, array $stmts, ?\ReflectionClass $sut): string
    {
        $finder = new NodeFinder();
        $printer = new Standard();

        /** @var Node\Stmt\ClassMethod|null $method */
        $method = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $f['test']);
        $body = $method?->stmts ?? [];

        /** @var Node\Stmt\Expression|null $assertion */
        $assertion = $finder->findFirst($body, static fn (Node $n): bool => $n instanceof Node\Stmt\Expression
            && ($n->expr instanceof Node\Expr\StaticCall || $n->expr instanceof Node\Expr\MethodCall)
            && $n->expr->name instanceof Node\Identifier
            && str_starts_with($n->expr->name->toString(), 'assert')
            && $n->getStartLine() <= $f['line'] && $f['line'] <= $n->getEndLine());

        $md = sprintf("\n- `%s` (ligne %d)", $f['test'], $f['line']);

        // On supprime de l'assertion les commentaires d'étape ('//ALORS...).
        if (null !== $assertion) {
            $bare = clone $assertion;
            $bare->setAttribute('comments', []);
            $md .= ' : `' . trim((string) preg_replace('/\s+/', ' ', $printer->prettyPrint([$bare]))) . '`';
        }
        $md .= sprintf("\n  - obtenu `%s`, attendu `%s`", $f['actual'], $f['expected']);

        $inputs = $this->inputs($body, $assertion, $printer);
        if ('number' === $f['kind']) {
            $expected = (float) $f['expected'];
            if (0.0 !== $expected) {
                $md .= sprintf(' (écart %.2f %%)', abs((float) $f['actual'] - $expected) / abs($expected) * 100);
            }
            $node = $assertion?->expr->args[0]->value ?? null;
            $md .= "\n  - " . $this->diagnosis($f['expected'], $node, $inputs);
        }
        if ([] !== $inputs) {
            $md .= "\n  - Données numériques du test : " . implode(', ', \array_slice($inputs, 0, 12));
        }

        return $md . $this->sutCode($body, $assertion, $sut) . "\n";
    }

    private function diagnosis(string $expected, ?Node\Expr $node, array $inputs): string
    {
        $literal = $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_
            || ($node instanceof Node\Expr\UnaryMinus && ($node->expr instanceof Node\Scalar\Int_ || $node->expr instanceof Node\Scalar\Float_));

        return match (true) {
            [] === $inputs => 'données du test indisponibles : recalcule depuis le code ci-dessous.',
            null !== $node && !$literal => "l'attendu est une expression : vérifie que sa formule est celle du code ci-dessous.",
            isset($inputs[(string) (float) $expected]) => sprintf('`%s` figure parmi les données du test : le test attend peut-être une donnée au lieu du résultat.', $expected),
            default => sprintf("`%s` n'apparaît dans aucune donnée du test : c'est un résultat calculé de tête.", $expected),
        };
    }

    /** @return array<string, string> valeur flottante => littéral tel qu'écrit, hors assertion en échec */
    private function inputs(array $body, ?Node $assertion, Standard $printer): array
    {
        $inputs = [];

        /** @var list<Node\Scalar\Int_|Node\Scalar\Float_|Node\Scalar\String_> $literals */
        $literals = (new NodeFinder())->find($body, static fn (Node $n): bool => $n instanceof Node\Scalar\Int_ || $n instanceof Node\Scalar\Float_
            || ($n instanceof Node\Scalar\String_ && is_numeric($n->value)));

        foreach ($literals as $literal) {
            if (null !== $assertion && $literal->getStartFilePos() >= $assertion->getStartFilePos() && $literal->getEndFilePos() <= $assertion->getEndFilePos()) {
                continue;
            }
            $inputs[(string) (float) $literal->value] ??= trim($printer->prettyPrintExpr($literal), "'");
        }

        return $inputs;
    }

    /**
     * Code réel des méthodes publiques du SUT qui sont appelées par le test avant l'assertion.
     */
    private function sutCode(array $body, ?Node $assertion, ?\ReflectionClass $sut): string
    {
        if (null === $sut) {
            return '';
        }
        $called = [];
        foreach ((new NodeFinder())->findInstanceOf($body, Node\Expr\MethodCall::class) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
            if (null !== $name && $sut->hasMethod($name) && $sut->getMethod($name)->isPublic()
                && (null === $assertion || $call->getStartFilePos() < $assertion->getStartFilePos())) {
                $called[$name] = $sut->getMethod($name);
            }
        }

        $md = '';
        foreach (\array_slice($called, 0, 2) as $m) {
            $code = MethodSource::full($m);
            $md .= sprintf("\n  - Code réel de `%s::%s()` :", $sut->getShortName(), $m->getName())
                . (null !== $code ? "\n" . preg_replace('/^/m', '        ', $code) : ' (trop long : lis sa signature et déduis la logique)');
        }

        return $md;
    }

    private function procedure(array $failures): string
    {
        $kinds = array_unique(array_column($failures, 'kind'));
        $md = "\nProcédure :\n";

        if (\in_array('number', $kinds, true)) {
            $md .= "1. Lis la formule dans le code réel ci-dessus et applique-la, étape par étape, aux données du test.\n"
                . "2. Écris l'attendu comme une EXPRESSION de ces données (ex. `(10 * 100.0 + 5 * 105.0) / (10 + 5)`) : PHP fait le calcul, pas toi.\n"
                . "3. Pour un flottant, utilise `assertEqualsWithDelta(attendu, \$obtenu, 0.0001)` plutôt que `assertSame`.\n"
                . "4. Ne recopie pas la valeur obtenue sans avoir vérifié la formule : le test validerait un éventuel bug du SUT.\n";
        }
        if (\in_array('boolean', $kinds, true)) {
            $md .= "- Booléen : suis le code réel pas à pas avec les données du test (quelle condition est fausse, quelle valeur est lue). "
                . "Adapte les DONNÉES pour que le chemin annoncé par le nom du test soit pris ; ne change l'attendu que si le nom du test lui-même était faux.\n";
        }

        return $md;
    }

    private function parse(?string $file): array
    {
        if (null === $file || !is_file($file)) {
            return [];
        }
        try {
            return $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }
    }
}