<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** @noinspection PhpUnused */
#[AsTaggedItem(priority: 80)]
final readonly class SetUpStubShadowingPreFixer implements TestPreFixerInterface
{
    private const MOCK_OBJECT = 'PHPUnit\Framework\MockObject\MockObject';

    public function __construct(
        private Parser $parser,
        private CoveredClassReader $coveredClass,
        private SutDependencyUsage $usage,
        private SutPropertyFinder $sutPropertyFinder,
        private SourceSpan $sourceSpan,
    ) {
    }

    /**
     * @throws \ReflectionException
     */
    public function fix(string $errorOutput, string $testCode): ?string
    {
        $sutClass = $this->coveredClass->read($testCode);
        if (null === $sutClass || !class_exists($sutClass)) {
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

        $finder = new NodeFinder();
        $setUp = null;
        $tests = [];
        $existing = [];
        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $m) {
            $existing[strtolower($m->name->toString())] = true;
            if ('setup' === strtolower($m->name->toString())) {
                $setUp = $m;
            } elseif ($this->isTest($m)) {
                $tests[] = $m;
            }
        }
        if (null === $setUp) {
            return null;
        }

        // 1. Stubs de setUp() déplaçables : instruction de premier niveau, sans variable locale
        $candidates = [];
        foreach ($setUp->stmts ?? [] as $statement) {
            $stub = $statement instanceof Node\Stmt\Expression ? $this->stubOf($statement) : null;
            if (null !== $stub && !$this->usesLocals($statement)) {
                $candidates[$stub['property'] . '::' . $stub['method']] ??= [$stub, $statement];
            }
        }

        // 2. Stubs que chaque test configure lui-même
        $own = [];
        foreach ($tests as $i => $test) {
            foreach ($finder->findInstanceOf($test->stmts ?? [], Node\Stmt\Expression::class) as $statement) {
                if (null !== $stub = $this->stubOf($statement)) {
                    $own[$i][$stub['property'] . '::' . $stub['method']] = true;
                }
            }
        }

        $sutReflection = new \ReflectionClass($sutClass);
        $sutProperty = $this->sutPropertyFinder->find($stmts, $sutClass);
        $edits = []; // position de début → [longueur, texte] ; longueur 0 = insertion
        $helpers = '';

        foreach ($candidates as $key => [$stub, $statement]) {
            if ([] === array_filter($own, static fn (array $keys): bool => isset($keys[$key]))) {
                continue; // aucun test n'écrase ce stub : rien à corriger
            }

            $helper = 'stubDefault' . ucfirst($stub['property']) . ucfirst($stub['method']);
            $dependency = $this->dependencyOf($stmts, $stub['property']);
            $reaching = null === $dependency
                ? []
                : $this->usage->publicMethodsReaching($sutReflection, $dependency, $stub['method']);
            if (isset($existing[strtolower($helper)]) || [] === $reaching) {
                continue; // analyse impossible : on ne touche à rien, le LLM reçoit l'indice
            }

            // a. Retrait du stub et de son commentaire dans setUp()
            $start = $statement->getStartFilePos();
            foreach ($statement->getComments() as $comment) {
                $start = min($start, $comment->getStartFilePos());
            }
            [$from, $length] = SourceSpan::lines($testCode, $start, $statement->getEndFilePos());
            $edits[$from] = [$length, ''];

            // b. Le même stub, dans une méthode privée
            $body = substr($testCode, $statement->getStartFilePos(), $statement->getEndFilePos() - $statement->getStartFilePos() + 1);
            $bodyIndent = $this->sourceSpan->indentAt($testCode, $statement->getStartFilePos());
            $methodIndent = $this->sourceSpan->indentAt($testCode, $setUp->getStartFilePos());
            $helpers .= sprintf("\n\n%sprivate function %s(): void\n%s{\n%s%s\n%s}", $methodIndent, $helper, $methodIndent, $bodyIndent, $body, $methodIndent);

            // c. Appel explicite dans les tests qui en ont besoin
            foreach ($tests as $i => $test) {
                $first = $test->stmts[0] ?? null;
                if (null === $first || isset($own[$i][$key]) || !$this->callsAny($test, $sutProperty, $sutClass, $reaching)) {
                    continue;
                }
                $pos = $first->getStartFilePos();
                $edits[$pos] = [0, ($edits[$pos][1] ?? '') . sprintf('$this->%s();', $helper) . "\n" . $this->sourceSpan->indentAt($testCode, $pos)];
            }
        }
        if ([] === $edits) {
            return null;
        }
        $edits[$setUp->getEndFilePos() + 1] = [0, $helpers];

        krsort($edits); // de la fin vers le début : les positions restent valides
        foreach ($edits as $start => [$length, $text]) {
            $testCode = substr_replace($testCode, $text, $start, $length);
        }

        return $testCode;
    }

    /** `$this->propriété->expects(…)->method('nom')…;` @return array{property: string, method: string}|null */
    private function stubOf(Node\Stmt\Expression $statement): ?array
    {
        $node = $statement->expr;
        $method = null;
        while ($node instanceof Node\Expr\MethodCall) {
            $arg = $node->args[0] ?? null;
            if ($node->name instanceof Node\Identifier && 'method' === $node->name->toString()
                && $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                $method = $arg->value->value;
            }
            $node = $node->var;
        }

        return null !== $method && $node instanceof Node\Expr\PropertyFetch
        && $node->var instanceof Node\Expr\Variable && 'this' === $node->var->name
        && $node->name instanceof Node\Identifier
            ? ['property' => $node->name->toString(), 'method' => $method]
            : null;
    }

    private function usesLocals(Node $node): bool
    {
        foreach ((new NodeFinder())->findInstanceOf($node, Node\Expr\Variable::class) as $variable) {
            if ('this' !== $variable->name) {
                return true; // le déplacer casserait la référence
            }
        }

        return false;
    }

    private function isTest(Node\Stmt\ClassMethod $m): bool
    {
        if (!$m->isPublic()) {
            return false;
        }
        if (str_starts_with(strtolower($m->name->toString()), 'test')) {
            return true;
        }
        foreach ($m->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ('PHPUnit\Framework\Attributes\Test' === $attr->name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Classe mockée par une propriété du test : `private X&MockObject $p;` ou `MockObject|X`. */
    private function dependencyOf(array $stmts, string $property): ?string
    {
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Stmt\Property::class) as $prop) {
            foreach ($prop->props as $item) {
                if ($item->name->toString() !== $property) {
                    continue;
                }
                $type = $prop->type;
                $names = [];
                foreach ($type instanceof Node\IntersectionType || $type instanceof Node\UnionType ? $type->types : [$type] as $t) {
                    if ($t instanceof Node\Name && self::MOCK_OBJECT !== $t->toString()) {
                        $names[] = $t->toString();
                    }
                }

                return 1 === \count($names) ? $names[0] : null;
            }
        }

        return null;
    }

    /** Le test appelle-t-il l'une de ces méthodes sur le SUT (propriété du test ou variable locale créée avec `new`) ? */
    private function callsAny(Node\Stmt\ClassMethod $test, ?string $sutProperty, string $sutClass, array $names): bool
    {
        $finder = new NodeFinder();
        $sutVars = [];
        foreach ($finder->findInstanceOf($test->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)
                && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name
                && $assign->expr->class->toString() === $sutClass) {
                $sutVars[$assign->var->name] = true;
            }
        }

        foreach ($finder->findInstanceOf($test->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
            if (!$call->name instanceof Node\Identifier || !\in_array($call->name->toString(), $names, true)) {
                continue;
            }
            $receiver = $call->var;
            if ($receiver instanceof Node\Expr\PropertyFetch && null !== $sutProperty
                && $receiver->var instanceof Node\Expr\Variable && 'this' === $receiver->var->name
                && $receiver->name instanceof Node\Identifier && $receiver->name->toString() === $sutProperty) {
                return true;
            }
            if ($receiver instanceof Node\Expr\Variable && \is_string($receiver->name) && isset($sutVars[$receiver->name])) {
                return true;
            }
        }

        return false;
    }
}