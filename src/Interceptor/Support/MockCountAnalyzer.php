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
    // language=RegExp
    private const TOO_MANY = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\R[\w\\\\]+::(?<method>\w+)\(.*?\)(?::\s*\S+)?\s+was not expected to be called more than (?:once|\d+ times?)\./m';

    // « Expectation failed for method name is "m" when invoked N time(s). Method was expected to be called N time(s), actually called K time(s). »
    // language=RegExp
    private const WRONG = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\RExpectation failed for method name is "(?<method>\w+)" when invoked \d+ times?\.\RMethod was expected to be called \d+ times?, actually called (?<observed>\d+) times?\./m';

    // « Classe::méthode(args): type was not expected to be called. » (never() violé, sans « more than »)
    // language=RegExp
    private const NEVER_VIOLATED = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\R[\w\\\\]+::(?<method>\w+)\(.*?\)(?::\s*\S+)?\s+was not expected to be called\./m';

    public function __construct(
        private Parser $parser,
        private CoveredClassReader $coveredClass,
        private SutPropertyFinder $sutProperty,
    ) {
    }

    /**
     * @return list<MockCountFinding>
     * @throws \ReflectionException
     */
    public function analyze(string $report, string $testCode, bool $withNever = false): array
    {
        $problems = $this->problems($report, $withNever);
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
    private function problems(string $report, bool $withNever): array
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
        if ($withNever) {
            preg_match_all(self::NEVER_VIOLATED, $report, $m, PREG_SET_ORDER);
            foreach ($m as $e) {
                $found[] = ['kind' => 'never-violated', 'test' => $e['test'], 'method' => $e['method'], 'observed' => null];
            }
        }

        return $found;
    }

    /**
     * @throws \ReflectionException
     */
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
        $neverCalled = null;
        if (null !== $sut && 1 === \count($calls)) {
            $entry = $sut->getMethod($calls[0]->name->toString());

            // PHPUnit a mesuré un appel, la preuve statique « jamais appelé » ne doit donc pas être produite pour ce type
            $neverCalled = ('never-violated' === $p['kind'] || $this->calledByTest($body, $p['method']))
                ? null
                : $this->neverCalledFrom($sut, $entry, $p['method']);

            if (null !== $neverCalled) {
                $why = $neverCalled;
            } else {
                [$derived, $why] = $this->derive($entry, $p['method'], $this->argsOf($calls[0], $entry), $assigns);
            }
        } elseif (null !== $sut) {
            $why = 'zéro ou plusieurs appels du SUT dans le test';
        }

        return new MockCountFinding(
            $p['kind'], $p['test'], $p['method'], $p['observed'], $derived, $why,
            $matcher, null === $matcher ? null : $this->limitOf($matcher), $expectation, $moveBefore, $neverCalled,
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
     * Les paramètres et les variables locales des closures n'existent pas dans la méthode de test : on les ignore.
     */
    public function movable(Node\Stmt $expectation, array $before): bool
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

        $local = [];
        $closures = $finder->find($expectation, static fn (Node $n): bool => $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction);
        foreach ($closures as $closure) {
            foreach ($closure->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                    $local[$param->var->name] = true;
                }
            }
            if ($closure instanceof Node\Expr\Closure) {
                foreach ($finder->findInstanceOf($closure->stmts, Node\Expr\Assign::class) as $assign) {
                    if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)) {
                        $local[$assign->var->name] = true;
                    }
                }
            }
        }

        foreach ($finder->findInstanceOf($expectation, Node\Expr\Variable::class) as $variable) {
            if (!\is_string($variable->name)) {
                return false;
            }
            if ('this' === $variable->name || isset($local[$variable->name])) {
                continue;
            }
            if (!isset($defined[$variable->name])) {
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
     * Le test appelle-t-il lui-même la méthode du mock (hors chaîne `->method('nom')`) ?
     *
     * @param list<Node\Stmt> $body
     */
    private function calledByTest(array $body, string $method): bool
    {
        return null !== (new NodeFinder())->findFirst($body, static fn (Node $n): bool => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall)
                && $n->name instanceof Node\Identifier && 0 === strcasecmp($n->name->toString(), $method));
    }

    /**
     * Preuve statique qu'aucune exécution de $entry n'appelle $dependencyMethod : on parcourt les méthodes de la classe
     * atteintes par `$this->m()`, `self::m()` et `static::m()`. Toute situation où la preuve n'est pas sûre donne null.
     */
    private function neverCalledFrom(\ReflectionClass $sut, \ReflectionMethod $entry, string $dependencyMethod): ?string
    {
        $file = $sut->getFileName();
        if (false === $file || $entry->getDeclaringClass()->getName() !== $sut->getName()
            || false !== $sut->getParentClass() || [] !== $sut->getTraitNames()) {
            return null; // héritage ou trait : `$this->m()` peut viser du code hors de ce fichier
        }
        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $stmts = $traverser->traverse($stmts);

        $class = (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\Class_
            && null !== $n->name && $n->name->toString() === $sut->getShortName());
        if (!$class instanceof Node\Stmt\Class_) {
            return null;
        }

        $methods = [];
        foreach ($class->getMethods() as $m) {
            $methods[strtolower($m->name->toString())] = $m;
        }
        if (!$this->plainConstructor($methods['__construct'] ?? null)) {
            return null; // un collaborateur construit ici pourrait recevoir le mock
        }

        $queue = [strtolower($entry->getName())];
        $visited = [];
        while ([] !== $queue) {
            $key = array_shift($queue);
            if (isset($visited[$key])) {
                continue;
            }
            $method = $methods[$key] ?? null;
            if (null === $method) {
                return null; // méthode appelée mais absente de la classe : __call, héritage…
            }
            $visited[$key] = $method->name->toString();

            $callees = $this->scanMethod($method, $dependencyMethod, $sut);
            if (null === $callees) {
                return null;
            }
            $queue = [...$queue, ...$callees];
        }

        return sprintf(
            "aucun chemin de `%s()` n'atteint `%s()` (méthodes parcourues : %s)",
            $entry->getName(), $dependencyMethod, implode(', ', array_values($visited)),
        );
    }

    /**
     * Constructeur absent, ou limité à `$this->propriété = $paramètre`.
     */
    private function plainConstructor(?Node\Stmt\ClassMethod $constructor): bool
    {
        foreach ($constructor?->stmts ?? [] as $statement) {
            if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Node\Expr\Assign
                || !$statement->expr->var instanceof Node\Expr\PropertyFetch
                || !$statement->expr->var->var instanceof Node\Expr\Variable || 'this' !== $statement->expr->var->var->name
                || !$statement->expr->expr instanceof Node\Expr\Variable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|null méthodes de la classe appelées par $method (en minuscules) ;
     *                           null si elle peut atteindre la dépendance ou si l'analyse n'est pas sûre
     */
    private function scanMethod(Node\Stmt\ClassMethod $method, string $dependencyMethod, \ReflectionClass $sut): ?array
    {
        $callees = [];
        foreach ((new NodeFinder())->find($method->stmts ?? [], static fn (Node $n): bool => true) as $n) {
            if ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) {
                if (!$n->name instanceof Node\Identifier || 0 === strcasecmp($n->name->toString(), $dependencyMethod)) {
                    return null; // appel dynamique, ou la dépendance est atteinte
                }
                if ($n->var instanceof Node\Expr\Variable && 'this' === $n->var->name) {
                    $callees[] = strtolower($n->name->toString());
                }
            } elseif ($n instanceof Node\Expr\StaticCall) {
                if (!$n->class instanceof Node\Name || !$n->name instanceof Node\Identifier
                    || 'parent' === strtolower($n->class->toString())
                    || 0 === strcasecmp($n->name->toString(), $dependencyMethod)) {
                    return null;
                }
                if (\in_array(strtolower($n->class->toString()), ['self', 'static'], true)) {
                    $callees[] = strtolower($n->name->toString());
                }
            } elseif ($n instanceof Node\Expr\FuncCall) {
                if (!$n->name instanceof Node\Name
                    || \in_array(strtolower($n->name->toString()), ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'], true)) {
                    return null;
                }
            } elseif ($n instanceof Node\Expr\Variable && 'this' === $n->name) {
                if (!$this->isReceiver($n)) {
                    return null; // $this passé, retourné ou affecté
                }
            } elseif (($n instanceof Node\Expr\PropertyFetch || $n instanceof Node\Expr\NullsafePropertyFetch)
                && $n->var instanceof Node\Expr\Variable && 'this' === $n->var->name) {
                if (!$n->name instanceof Node\Identifier
                    || (!$this->isReceiver($n) && $this->mayHoldObject($sut, $n->name->toString()))) {
                    return null; // une propriété pouvant contenir le mock est passée ailleurs
                }
            }
        }

        return array_values(array_unique($callees));
    }

    /**
     * Le nœud est-il le récepteur d'un appel de méthode ou d'un accès à une propriété (`$n->x`, `$n->m()`) ?
     */
    private function isReceiver(Node $n): bool
    {
        $parent = $n->getAttribute('parent');

        return ($parent instanceof Node\Expr\MethodCall || $parent instanceof Node\Expr\NullsafeMethodCall
                || $parent instanceof Node\Expr\PropertyFetch || $parent instanceof Node\Expr\NullsafePropertyFetch)
            && $parent->var === $n;
    }

    /**
     * Seuls les scalaires déclarés (int, float, string, bool) ne peuvent pas contenir le mock.
     */
    private function mayHoldObject(\ReflectionClass $sut, string $property): bool
    {
        if (!$sut->hasProperty($property)) {
            return true;
        }
        $type = $sut->getProperty($property)->getType();

        return !$type instanceof \ReflectionNamedType || !\in_array($type->getName(), ['int', 'float', 'string', 'bool'], true);
    }

    /**
     * Nombre d'appels de $dependencyMethod causés par UNE exécution de $entry, déduit du code : appels directs
     * (`$this->propriété->méthode()`) et appels vers les autres méthodes de la classe (`$this->autre()`), publiques ou privées.
     * Boucles `for` à bornes constantes et `foreach` sur un paramètre fourni en littéral par le test sont comptées.
     * Tout le reste (if, while, return ou throw avant l'appel, appel dynamique, héritage, closure…) donne null : on ne devine pas.
     *
     * @param array<string, Node\Expr>       $args
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

        $shortName = $entry->getDeclaringClass()->getShortName();
        $class = (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\Class_
            && null !== $n->name && $n->name->toString() === $shortName);
        if (!$class instanceof Node\Stmt\Class_) {
            return [null, 'classe introuvable dans le SUT'];
        }

        $methods = [];
        foreach ($class->getMethods() as $m) {
            $methods[strtolower($m->name->toString())] = $m;
        }
        $method = $methods[strtolower($entry->getName())] ?? null;
        if (null === $method) {
            return [null, 'méthode introuvable dans le SUT'];
        }

        $notes = [];
        $count = $this->callsOf($method, $methods, $dependencyMethod, $args, $assigns, [], $notes);
        if (\is_string($count)) {
            return [null, $count];
        }

        return [$count, [] === $notes ? 'appel direct, sans boucle' : implode(' ; ', array_unique($notes))];
    }

    /**
     * @param array<string, Node\Stmt\ClassMethod> $methods
     * @param array<string, Node\Expr>             $args    arguments de la méthode d'entrée (vides pour les méthodes relayées)
     * @param array<string, list<Node\Expr>>       $assigns
     * @param list<string>                         $visiting
     * @param list<string>                         $notes
     *
     * @return int|string le nombre d'appels, ou la raison pour laquelle il n'est pas déductible
     */
    private function callsOf(
        Node\Stmt\ClassMethod $method,
        array $methods,
        string $dependency,
        array $args,
        array $assigns,
        array $visiting,
        array &$notes
    ): int|string {
        $key = strtolower($method->name->toString());
        if (\in_array($key, $visiting, true)) {
            return "méthodes de la classe qui s'appellent entre elles";
        }
        $visiting[] = $key;

        $finder = new NodeFinder();
        $body = $method->stmts ?? [];

        $events = []; // [appel, nombre d'appels de la dépendance qu'il provoque]
        foreach ($finder->findInstanceOf($body, Node\Expr\MethodCall::class) as $call) {
            if ($call->var instanceof Node\Expr\PropertyFetch && $call->var->var instanceof Node\Expr\Variable && 'this' === $call->var->var->name) {
                if ($call->name instanceof Node\Identifier && 0 === strcasecmp($call->name->toString(), $dependency)) {
                    $events[] = [$call, 1];
                }
            } elseif ($call->var instanceof Node\Expr\Variable && 'this' === $call->var->name) {
                if (!$call->name instanceof Node\Identifier) {
                    return "appel dynamique d'une méthode de la classe";
                }
                $callee = $methods[strtolower($call->name->toString())] ?? null;
                if (null === $callee) {
                    return sprintf('méthode %s() absente de la classe (héritage, trait ou __call)', $call->name->toString());
                }
                $weight = $this->callsOf($callee, $methods, $dependency, [], $assigns, $visiting, $notes);
                if (\is_string($weight)) {
                    return $weight;
                }
                if ($weight > 0) {
                    $events[] = [$call, $weight];
                    $notes[] = sprintf('%s() : %d appel(s)', $call->name->toString(), $weight);
                }
            }
        }
        if ([] === $events) {
            return 0;
        }

        // Instruction de premier niveau qui contient un nœud
        $top = static function (Node $node) use ($method): ?int {
            for ($n = $node; null !== $n; $n = $n->getAttribute('parent')) {
                if ($n->getAttribute('parent') === $method) {
                    $index = array_search($n, $method->stmts ?? [], true);

                    return false === $index ? null : $index;
                }
            }

            return null;
        };

        $lastEvent = -1;
        foreach ($events as [$call]) {
            $index = $top($call);
            if (null === $index) {
                return 'appel hors du corps de la méthode';
            }
            $lastEvent = max($lastEvent, $index);
        }

        // Une sortie (return, break, continue, throw, exit) n'est admise qu'après la dernière instruction qui appelle,
        // ou comme dernier return du corps
        $last = [] === $body ? null : $body[array_key_last($body)];
        $exits = $finder->find($body, static fn (Node $n): bool => $n instanceof Node\Stmt\Return_ || $n instanceof Node\Stmt\Break_
            || $n instanceof Node\Stmt\Continue_ || $n instanceof Node\Expr\Throw_ || $n instanceof Node\Expr\Exit_);
        foreach ($exits as $exit) {
            if ($exit === $last) {
                continue;
            }
            $index = $top($exit);
            if (null === $index || $index <= $lastEvent) {
                return "return, break, continue ou throw avant ou autour de l'appel";
            }
        }

        $total = 0;
        foreach ($events as [$call, $weight]) {
            $times = 1;
            for ($n = $call->getAttribute('parent'); null !== $n && $n !== $method; $n = $n->getAttribute('parent')) {
                if ($n instanceof Node\Stmt\For_) {
                    if (null === $count = $this->forIterations($n)) {
                        return 'boucle for à bornes non constantes';
                    }
                    $times *= $count;
                    $notes[] = sprintf('boucle for : %d itération(s)', $count);
                } elseif ($n instanceof Node\Stmt\Foreach_) {
                    if (null === $count = $this->foreachIterations($n, $method, $args, $assigns)) {
                        return 'foreach sur une valeur que le test ne fournit pas en littéral';
                    }
                    $times *= $count;
                    $notes[] = sprintf('foreach : %d élément(s) fourni(s) par le test', $count);
                } elseif ($this->isConditional($n)) {
                    return 'appel conditionnel (if, switch, match, while, ?:, && ou ||)';
                }
            }
            $total += $times * $weight;
        }

        return $total;
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
