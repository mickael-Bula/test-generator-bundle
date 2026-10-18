<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;

final readonly class MockCountAnalyzer
{
    // « Classe::méthode(args): type was not expected to be called more than once. »
    private const TOO_MANY = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\R[\w\\\\]+::(?<method>\w+)\(.*?\)(?::\s*\S+)?\s+was not expected to be called more than (?:once|\d+ times?)\./m';
    // « Expectation failed for method name is "m" when invoked N time(s). Method was expected to be called N time(s), actually called K time(s). »
    private const WRONG = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\RExpectation failed for method name is "(?<method>\w+)" when invoked \d+ times?\.\RMethod was expected to be called \d+ times?, actually called (?<observed>\d+) times?\./m';

    public function __construct(
        private Parser $parser,
        private CoveredClassReader $coveredClass,
        private SutPropertyFinder $sutProperty,
    ) {
    }

    /**
     * @return list<MockCountFinding>
     */
    public function analyze(string $report, string $testCode): array
    {
        $problems = $this->problems($report);
        if ([] === $problems) {
            return [];
        }
        try {
            $stmts = $this->parser->parse($testCode) ?? [];
        } catch (Error) {
            return [];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $sutClass = $this->coveredClass->read($testCode);
        $sut = null !== $sutClass && class_exists($sutClass) ? new \ReflectionClass($sutClass) : null;
        $property = null !== $sut ? $this->sutProperty->find($stmts, $sut->getName()) : null;

        $finder = new NodeFinder();
        $findings = [];
        foreach ($problems as $problem) {
            $test = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $problem['test']);
            if ($test instanceof Node\Stmt\ClassMethod) {
                $findings[] = $this->finding($problem, $test, $sut, $property);
            }
        }

        return $findings;
    }

    /**
     * @return list<array{kind: string, test: string, method: string, observed: ?int}>
     */
    private function problems(string $report): array
    {
        $found = [];
        preg_match_all(self::TOO_MANY, $report, $m, PREG_SET_ORDER);
        foreach ($m as $e) {
            $found[] = ['kind' => 'too-many', 'test' => $e['test'], 'method' => $e['method'], 'observed' => null];
        }
        preg_match_all(self::WRONG, $report, $m, PREG_SET_ORDER);
        foreach ($m as $e) {
            $found[] = ['kind' => 'wrong-count', 'test' => $e['test'], 'method' => $e['method'], 'observed' => (int) $e['observed']];
        }

        return $found;
    }

    private function finding(array $p, Node\Stmt\ClassMethod $test, ?\ReflectionClass $sut, ?string $property): MockCountFinding
    {
        $finder = new NodeFinder();
        $body = $test->stmts ?? [];

        // Variables locales : valeurs affectées, et celles créées avec `new SUT`
        $assigns = [];
        $sutVars = [];
        foreach ($finder->findInstanceOf($body, Node\Expr\Assign::class) as $assign) {
            if (!$assign->var instanceof Node\Expr\Variable || !\is_string($assign->var->name)) {
                continue;
            }
            $assigns[$assign->var->name][] = $assign->expr;
            if (null !== $sut && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name
                && $assign->expr->class->toString() === $sut->getName()) {
                $sutVars[$assign->var->name] = true;
            }
        }
        $isSut = static fn (Node\Expr $r): bool => ($r instanceof Node\Expr\PropertyFetch && null !== $property
                && $r->var instanceof Node\Expr\Variable && 'this' === $r->var->name
                && $r->name instanceof Node\Identifier && $r->name->toString() === $property)
            || ($r instanceof Node\Expr\Variable && \is_string($r->name) && isset($sutVars[$r->name]));

        // L'attente du mock sur cette méthode (instruction de premier niveau), si elle est unique
        $expectations = [];
        foreach ($body as $i => $statement) {
            if ($statement instanceof Node\Stmt\Expression && null !== $matcher = $this->matcherOf($statement->expr, $p['method'])) {
                $expectations[] = [$i, $statement, $matcher];
            }
        }
        [$index, $expectation, $matcher] = 1 === \count($expectations) ? $expectations[0] : [null, null, null];

        // L'action : première instruction qui appelle le SUT (ou une invocation par réflexion) ; appels publics du SUT
        $actionIndex = null;
        $calls = [];
        foreach ($body as $i => $statement) {
            foreach ($finder->findInstanceOf($statement, Node\Expr\MethodCall::class) as $call) {
                $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
                if ($isSut($call->var) || \in_array(strtolower($name), ['invoke', 'invokeargs'], true)) {
                    $actionIndex ??= $i;
                }
                if ($isSut($call->var) && null !== $sut && $sut->hasMethod($name)
                    && $sut->getMethod($name)->isPublic() && !$sut->getMethod($name)->isStatic()) {
                    $calls[] = $call;
                }
            }
        }

        // Attente déclarée après l'action : ancre = instruction marquée « QUAND » (sinon l'action), si déplaçable
        $moveBefore = null;
        if (null !== $index && null !== $actionIndex && $index > $actionIndex) {
            $anchor = $actionIndex;
            foreach (\array_slice($body, 0, $actionIndex + 1, true) as $i => $statement) {
                foreach ($statement->getComments() as $comment) {
                    if (1 === preg_match('/^\/\/\s*QUAND\b/u', $comment->getText())) {
                        $anchor = $i;
                        break 2;
                    }
                }
            }
            if ($this->movable($expectation, \array_slice($body, 0, $anchor))) {
                $moveBefore = $body[$anchor];
            }
        }

        // Nombre d'appels déduit du code du SUT
        $derived = null;
        $why = 'classe testée ou appel du SUT indéterminé';
        if (null !== $sut && 1 === \count($calls)) {
            $entry = $sut->getMethod($calls[0]->name->toString());
            [$derived, $why] = $this->derive($entry, $p['method'], $this->argsOf($calls[0], $entry), $assigns);
        } elseif (null !== $sut) {
            $why = 'zéro ou plusieurs appels du SUT dans le test';
        }

        return new MockCountFinding(
            $p['kind'], $p['test'], $p['method'], $p['observed'], $derived, $why,
            $matcher, null === $matcher ? null : $this->limitOf($matcher), $expectation, $moveBefore,
        );
    }

    /**
     * L'argument de `expects()` dans une chaîne `->expects(M)->method('nom')…`, ou null si la chaîne ne vise pas $method.
     */
    private function matcherOf(Node\Expr $expr, string $method): ?Node\Expr
    {
        $matcher = null;
        $named = false;
        for ($n = $expr; $n instanceof Node\Expr\MethodCall; $n = $n->var) {
            $first = $n->args[0] ?? null;
            if (!$n->name instanceof Node\Identifier || !$first instanceof Node\Arg) {
                continue;
            }
            if ('expects' === $n->name->toString()) {
                $matcher = $first->value;
            }
            if ('method' === $n->name->toString() && $first->value instanceof Node\Scalar\String_
                && 0 === strcasecmp($first->value->value, $method)) {
                $named = true;
            }
        }

        return $named ? $matcher : null;
    }

    /**
     * once() => 1, exactly(N) => N ; les autres matchers (any, atLeastOnce, never…) ne sont pas des comptes exacts.
     */
    private function limitOf(Node\Expr $matcher): ?int
    {
        if (!($matcher instanceof Node\Expr\StaticCall || $matcher instanceof Node\Expr\MethodCall)
            || !$matcher->name instanceof Node\Identifier
        ) {
            return null;
        }
        $arg = $matcher->args[0] ?? null;

        return match (strtolower($matcher->name->toString())) {
            'once' => 1,
            'exactly' => $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\Int_ ? $arg->value->value : null,
            default => null,
        };
    }

    /**
     * Toutes les variables de l'instruction sont-elles déjà affectées avant le point d'insertion ?
     */
    private function movable(Node\Stmt $expectation, array $before): bool
    {
        $finder = new NodeFinder();
        $defined = [];
        foreach ($before as $statement) {
            foreach ($finder->findInstanceOf($statement, Node\Expr\Assign::class) as $assign) {
                if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)) {
                    $defined[$assign->var->name] = true;
                }
            }
        }
        foreach ($finder->findInstanceOf($expectation, Node\Expr\Variable::class) as $variable) {
            if (!\is_string($variable->name) || ('this' !== $variable->name && !isset($defined[$variable->name]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, Node\Expr> nom du paramètre => expression passée dans l'appel du test
     */
    private function argsOf(Node\Expr\MethodCall $call, \ReflectionMethod $entry): array
    {
        $params = $entry->getParameters();
        $map = [];
        foreach ($call->args as $i => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                return [];
            }
            $name = $arg->name?->toString() ?? (isset($params[$i]) ? $params[$i]->getName() : null);
            if (null !== $name) {
                $map[$name] = $arg->value;
            }
        }

        return $map;
    }

    /**
     * Nombre d'appels de $dependencyMethod causés par UNE exécution de $entry, déduit du code : boucles `for` à bornes
     * constantes, `foreach` sur un paramètre fourni en littéral par le test. Tout le reste (if, while, return anticipé,
     * appel via une méthode privée, closure…) donne null : on ne devine pas.
     *
     * @param array<string, Node\Expr>  $args
     * @param array<string, list<Node\Expr>> $assigns
     *
     * @return array{0: ?int, 1: string}
     */
    private function derive(\ReflectionMethod $entry, string $dependencyMethod, array $args, array $assigns): array
    {
        $file = $entry->getFileName();
        if (false === $file) {
            return [null, 'fichier du SUT introuvable'];
        }
        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [null, 'fichier du SUT illisible'];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $stmts = $traverser->traverse($stmts);

        $finder = new NodeFinder();
        $method = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $entry->getName());
        if (!$method instanceof Node\Stmt\ClassMethod) {
            return [null, 'méthode introuvable dans le SUT'];
        }
        $body = $method->stmts ?? [];

        $isCall = static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier
            && 0 === strcasecmp($n->name->toString(), $dependencyMethod)
            && $n->var instanceof Node\Expr\PropertyFetch && $n->var->var instanceof Node\Expr\Variable && 'this' === $n->var->var->name;
        $sites = $finder->find($body, $isCall);
        if ([] === $sites || \count($sites) !== \count($finder->find($stmts, $isCall))) {
            return [null, "l'appel n'est pas uniquement dans la méthode testée (il passe par une méthode privée)"];
        }
        if ([] !== $finder->find($body, static fn (Node $n): bool => $n instanceof Node\Stmt\Break_ || $n instanceof Node\Stmt\Continue_)) {
            return [null, 'break ou continue dans la méthode'];
        }
        $last = [] === $body ? null : $body[array_key_last($body)];
        foreach ($finder->findInstanceOf($body, Node\Stmt\Return_::class) as $return) {
            if ($return !== $last) {
                return [null, 'return anticipé'];
            }
        }

        $total = 0;
        $notes = [];
        foreach ($sites as $site) {
            $times = 1;
            for ($n = $site->getAttribute('parent'); null !== $n && $n !== $method; $n = $n->getAttribute('parent')) {
                if ($n instanceof Node\Stmt\For_) {
                    if (null === $count = $this->forIterations($n)) {
                        return [null, 'boucle for à bornes non constantes'];
                    }
                    $times *= $count;
                    $notes[] = sprintf('boucle for : %d itération(s)', $count);
                } elseif ($n instanceof Node\Stmt\Foreach_) {
                    if (null === $count = $this->foreachIterations($n, $method, $args, $assigns)) {
                        return [null, "foreach sur une valeur que le test ne fournit pas en littéral"];
                    }
                    $times *= $count;
                    $notes[] = sprintf('foreach : %d élément(s) fourni(s) par le test', $count);
                } elseif ($this->isConditional($n)) {
                    return [null, "appel conditionnel (if, switch, match, while, ?:, && ou ||)"];
                }
            }
            $total += $times;
        }

        return [$total, [] === $notes ? 'appel direct, sans boucle' : implode(' ; ', array_unique($notes))];
    }

    /**
     * `for ($i = A; $i <= B; ++$i)` ou `$i < B`, bornes entières littérales.
     */
    private function forIterations(Node\Stmt\For_ $for): ?int
    {
        if (1 !== \count($for->init) || 1 !== \count($for->cond) || 1 !== \count($for->loop)) {
            return null;
        }
        [$init, $cond, $step] = [$for->init[0], $for->cond[0], $for->loop[0]];
        if (!$init instanceof Node\Expr\Assign || !$init->var instanceof Node\Expr\Variable || !$init->expr instanceof Node\Scalar\Int_) {
            return null;
        }
        $name = $init->var->name;
        $inclusive = $cond instanceof Node\Expr\BinaryOp\SmallerOrEqual;
        if (!($inclusive || $cond instanceof Node\Expr\BinaryOp\Smaller)
            || !$cond->left instanceof Node\Expr\Variable || $cond->left->name !== $name || !$cond->right instanceof Node\Scalar\Int_) {
            return null;
        }
        if (!(($step instanceof Node\Expr\PreInc || $step instanceof Node\Expr\PostInc) && $step->var instanceof Node\Expr\Variable && $step->var->name === $name)) {
            return null;
        }

        return max(0, $cond->right->value - $init->expr->value + ($inclusive ? 1 : 0));
    }

    private function foreachIterations(Node\Stmt\Foreach_ $foreach, Node\Stmt\ClassMethod $method, array $args, array $assigns): ?int
    {
        if (!$foreach->expr instanceof Node\Expr\Variable || !\is_string($foreach->expr->name)) {
            return null;
        }
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $foreach->expr->name) {
                return isset($args[$foreach->expr->name]) ? $this->countItems($args[$foreach->expr->name], $assigns) : null;
            }
        }

        return null; // itère sur autre chose qu'un paramètre
    }

    /**
     * Éléments d'un tableau littéral, ou d'un `new Collection([...])`, éventuellement via une variable affectée une seule fois.
     */
    private function countItems(Node\Expr $expr, array $assigns, int $depth = 0): ?int
    {
        if ($depth > 3) {
            return null;
        }
        if ($expr instanceof Node\Expr\Variable && \is_string($expr->name)) {
            return 1 === \count($assigns[$expr->name] ?? []) ? $this->countItems($assigns[$expr->name][0], $assigns, $depth + 1) : null;
        }
        if ($expr instanceof Node\Expr\New_) {
            $first = $expr->args[0] ?? null;

            return [] === $expr->args ? 0 : ($first instanceof Node\Arg ? $this->countItems($first->value, $assigns, $depth + 1) : null);
        }
        if ($expr instanceof Node\Expr\Array_) {
            foreach ($expr->items as $item) {
                if (null === $item || $item->unpack) {
                    return null;
                }
            }

            return \count($expr->items);
        }

        return null;
    }

    private function isConditional(Node $n): bool
    {
        return $n instanceof Node\Stmt\If_ || $n instanceof Node\Stmt\ElseIf_ || $n instanceof Node\Stmt\Else_
            || $n instanceof Node\Stmt\Switch_ || $n instanceof Node\Stmt\Case_
            || $n instanceof Node\Expr\Match_ || $n instanceof Node\MatchArm
            || $n instanceof Node\Stmt\While_ || $n instanceof Node\Stmt\Do_
            || $n instanceof Node\Expr\Ternary || $n instanceof Node\Expr\BinaryOp\Coalesce
            || $n instanceof Node\Expr\BinaryOp\BooleanAnd || $n instanceof Node\Expr\BinaryOp\BooleanOr
            || $n instanceof Node\Expr\BinaryOp\LogicalAnd || $n instanceof Node\Expr\BinaryOp\LogicalOr
            || $n instanceof Node\Stmt\Catch_ || $n instanceof Node\Stmt\Finally_
            || $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction;
    }
}