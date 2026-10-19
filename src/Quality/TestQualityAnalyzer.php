<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

final readonly class TestQualityAnalyzer
{
    public function __construct(
        private Parser $parser,
        private CoveredClassReader $coveredClass,
        private CommentPolicy $comments,
    ) {
    }

    public function analyze(string $code, ?string $baseline = null): QualityAnalysis
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return new QualityAnalysis([], 0);
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $class = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        if (!$class instanceof Node\Stmt\Class_ || null === $class->namespacedName) {
            return new QualityAnalysis([], 0);
        }
        $testClass = $class->namespacedName->toString();

        $sutClass = $this->coveredClass->read($code);
        $sut = null !== $sutClass && class_exists($sutClass) ? new \ReflectionClass($sutClass) : null;

        // On parcourt la liste des méthodes retournées par `$class->getMethods()` que l'on passe successivement en paramètre de la closure `$this->isTest(...)`
        $tests = array_values(array_filter($class->getMethods(), $this->isTest(...)));
        if ([] === $tests) {
            return new QualityAnalysis(
                [new QualityViolation(QualityViolation::NO_TESTS, $testClass, 'noTestsFound', 'Aucune méthode de test dans la classe.', null)],
                0,
            );
        }

        $existing = null === $baseline ? [] : array_flip($this->methodNames($baseline));

        $violations = [];
        foreach ($tests as $test) {
            $name = $test->name->toString();
            if (isset($existing[$name])) {
                continue; // test déjà présent dans le fichier d'origine : on n'y touche pas
            }
            if (null !== $sut) {
                foreach ($this->nonPublicReflections($test, $sut) as $method) {
                    $violations[] = new QualityViolation(
                        QualityViolation::PRIVATE_CALL, $testClass, $name,
                        sprintf('Appel par Reflection de la méthode non publique %s::%s()', $sut->getShortName(), $method),
                        $test,
                    );
                }
            }
            array_push($violations, ...$this->trivialAssertions($test, $testClass));
        }

        if (null === $baseline) {
            // Commentaires interdits : un seul signalement par méthode (ou « classLevel » hors de toute méthode)
            $ranges = [];
            foreach ($class->getMethods() as $m) {
                $start = $m->getStartFilePos();
                if (null !== $doc = $m->getDocComment()) {
                    $start = min($start, $doc->getStartFilePos());
                }
                $ranges[$m->name->toString()] = [$start, $m->getEndFilePos()];
            }
            $perOwner = [];
            foreach ($this->comments->violations($code) as $c) {
                $owner = 'classLevel';
                foreach ($ranges as $method => [$start, $end]) {
                    if ($c['pos'] >= $start && $c['pos'] <= $end) {
                        $owner = $method;
                        break;
                    }
                }
                $perOwner[$owner][] = $c['line'];
            }
            foreach ($perOwner as $owner => $lines) {
                $violations[] = new QualityViolation(
                    QualityViolation::FORBIDDEN_COMMENT, $testClass, $owner,
                    sprintf('%d commentaire(s) interdit(s) (lignes %s)', \count($lines), implode(', ', $lines)),
                    null,
                );
            }
        }

        return new QualityAnalysis($violations, \count($tests));
    }

    private function isTest(Node\Stmt\ClassMethod $method): bool
    {
        if (!$method->isPublic() || $method->isStatic()) {
            return false;
        }
        if (str_starts_with(strtolower($method->name->toString()), 'test')) {
            return true;
        }
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ('PHPUnit\Framework\Attributes\Test' === $attr->name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string> méthodes non publiques du SUT atteintes par Reflection
     */
    private function nonPublicReflections(Node\Stmt\ClassMethod $test, \ReflectionClass $sut): array
    {
        $found = [];
        $nodes = (new NodeFinder())->find(
            $test->stmts ?? [],
            static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\New_
        );

        foreach ($nodes as $n) {
            $arg = null;
            if (
                $n instanceof Node\Expr\MethodCall
                && $n->name instanceof Node\Identifier
                && 'getmethod' === strtolower($n->name->toString())
            ) {
                $arg = $n->args[0] ?? null;
            } elseif (
                $n instanceof Node\Expr\New_
                && $n->class instanceof Node\Name
                && 'reflectionmethod' === strtolower($n->class->toString())
            ) {
                $arg = $n->args[1] ?? $n->args[0] ?? null; // new ReflectionMethod($objetOuClasse, 'nom') ou 'Classe::nom'
            }

            $value = $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
            if (\is_string($value) && str_contains($value, '::')) {
                $value = substr($value, (int) strrpos($value, '::') + 2);
            }
            if (\is_string($value) && $sut->hasMethod($value) && !$sut->getMethod($value)->isPublic()) {
                $found[] = $value;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<QualityViolation>
     */
    private function trivialAssertions(Node\Stmt\ClassMethod $test, string $testClass): array
    {
        $finder = new NodeFinder();
        $body = $test->stmts ?? [];

        $created = []; // variable => classe créée par `new` dans ce test
        foreach ($finder->findInstanceOf($body, Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)
                && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name) {
                $created[$assign->var->name] = $assign->expr->class->toString();
            }
        }

        $hasCheck = false;
        foreach ($finder->findInstanceOf($body, Node\Expr\MethodCall::class) as $call) {
            $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            if (str_starts_with($name, 'expectexception') || 'expectnottoperformassertions' === $name) {
                $hasCheck = true;
            }
            if ('expects' === $name) {
                $matcher = ($call->args[0] ?? null)?->value;
                $isAny = ($matcher instanceof Node\Expr\StaticCall || $matcher instanceof Node\Expr\MethodCall)
                    && $matcher->name instanceof Node\Identifier && 'any' === strtolower($matcher->name->toString());
                $hasCheck = $hasCheck || !$isAny;
            }
        }

        $trivial = [];
        foreach ($finder->findInstanceOf($body, Node\Stmt\Expression::class) as $statement) {
            $call = $statement->expr;
            if (!($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall) || !$this->isAssertion($call)) {
                continue;
            }
            if ($this->isTrivial($call, $created)) {
                $trivial[] = [$statement, $call->name->toString()];
            } else {
                $hasCheck = true;
            }
        }

        $violations = [];
        foreach ($trivial as [$statement, $assertion]) {
            $line = $statement->getStartLine();
            $violations[] = $hasCheck
                ? new QualityViolation(QualityViolation::TRIVIAL_ASSERTION, $testClass, $test->name->toString(), sprintf('Assertion triviale : %s() ne vérifie rien (ligne %d)', $assertion, $line), $statement)
                : new QualityViolation(QualityViolation::TRIVIAL_ONLY, $testClass, $test->name->toString(), sprintf('Le test ne vérifie rien : seule assertion triviale %s() (ligne %d)', $assertion, $line), null);
        }

        return $violations;
    }

    private function isAssertion(Node\Expr\StaticCall|Node\Expr\MethodCall $call): bool
    {
        if (!$call->name instanceof Node\Identifier || !str_starts_with($call->name->toString(), 'assert')) {
            return false;
        }
        if ($call instanceof Node\Expr\StaticCall) {
            return $call->class instanceof Node\Name
                && \in_array(strtolower($call->class->toString()), ['self', 'static', 'phpunit\framework\assert', 'phpunit\framework\testcase'], true);
        }

        return $call->var instanceof Node\Expr\Variable && 'this' === $call->var->name;
    }

    /**
     * @param array<string, string> $created
     */
    private function isTrivial(Node\Expr\StaticCall|Node\Expr\MethodCall $call, array $created): bool
    {
        $args = array_map(static fn ($a) => $a instanceof Node\Arg ? $a->value : null, $call->args);
        if ([] === $args || \in_array(null, $args, true)) {
            return false;
        }

        // assertInstanceOf(X::class, $v) où $v vient d'être créé avec `new X` : tautologie
        if ('assertinstanceof' === strtolower($call->name->toString()) && 2 === \count($args)
            && $args[0] instanceof Node\Expr\ClassConstFetch && $args[0]->class instanceof Node\Name
            && $args[0]->name instanceof Node\Identifier && 'class' === $args[0]->name->toString()
            && $args[1] instanceof Node\Expr\Variable && \is_string($args[1]->name)
            && ($created[$args[1]->name] ?? null) === $args[0]->class->toString()) {
            return true;
        }

        foreach ($args as $arg) {
            if (!$this->isConstant($arg)) {
                return false;
            }
        }

        return true;
    }

    private function isConstant(Node\Expr $e): bool
    {
        return match (true) {
            $e instanceof Node\Scalar\Int_, $e instanceof Node\Scalar\Float_, $e instanceof Node\Scalar\String_, $e instanceof Node\Expr\ConstFetch => true,
            $e instanceof Node\Expr\UnaryMinus => $this->isConstant($e->expr),
            $e instanceof Node\Expr\ClassConstFetch => $e->name instanceof Node\Identifier && 'class' === $e->name->toString(),
            $e instanceof Node\Expr\Array_ => [] === array_filter($e->items, fn ($i): bool => null === $i || !$this->isConstant($i->value)),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private function methodNames(string $code): array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        return array_map(
            static fn (Node\Stmt\ClassMethod $m): string => $m->name->toString(),
            (new NodeFinder())->findInstanceOf($stmts, Node\Stmt\ClassMethod::class),
        );
    }
}