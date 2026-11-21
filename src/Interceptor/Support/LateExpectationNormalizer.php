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

/**
 * Remonte avant le « QUAND » les `expects()` déclarés après le dernier appel du SUT.
 * Conditions requises :
 *  1. elle se trouve après le dernier appel du SUT (entre deux appels, elle reste en place : elle peut préparer le second) ;
 *  2. sa racine est un mock, jamais le SUT ;
 *  3. le test n'appelle pas lui-même la méthode du mock après elle ;
 *  4. toutes ses variables sont définies avant le point d'insertion (même règle que movable(), closures comprises).
 *
 * Si aucune de ces conditions n'est remplie, le code reste intact.
 * Un never() remonté qui devient rouge est le comportement voulu :
 * on passe d'un vert qui ne vérifie rien à un rouge honnête, que la boucle de réparation peut traiter.
 *
 * Indépendant du rapport : traite aussi les tests verts (never() déclaré après l'appel ne vérifie rien).
 */
final readonly class LateExpectationNormalizer
{
    public function __construct(
        private Parser $parser,
        private CoveredClassReader $coveredClass,
        private SutPropertyFinder $sutProperty,
        private MockCountAnalyzer $analyzer,
    ) {
    }

    /**
     * @param list<string> $skip tests à ne pas toucher (déjà présents dans le fichier existant en mode fusion)
     */
    public function normalize(string $testCode, array $skip = []): ?string
    {
        $sutClass = $this->coveredClass->read($testCode);
        if (null === $sutClass) {
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

        $sutClass = ltrim($sutClass, '\\');
        $property = $this->sutProperty->find($stmts, $sutClass);
        $eol = str_contains($testCode, "\r\n") ? "\r\n" : "\n";

        $edits = []; // [position, longueur, texte]
        $inserts = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $method) {
            $name = $method->name->toString();
            if (!str_starts_with($name, 'test') || \in_array($name, $skip, true)) {
                continue;
            }
            foreach ($this->lateExpectations($method, $sutClass, $property) as [$expectation, $anchor]) {
                $start = $expectation->getStartFilePos();
                $end = $expectation->getEndFilePos();

                $position = $anchor->getStartFilePos();
                foreach ($anchor->getComments() as $comment) {
                    $position = min($position, $comment->getStartFilePos());
                }
                $newline = strrpos(substr($testCode, 0, $position), "\n");
                $lineStart = false === $newline ? 0 : $newline + 1;
                $indent = substr($testCode, $lineStart, $position - $lineStart);
                if ('' !== trim($indent)) {
                    continue; // du code précède l'ancre sur sa ligne : on ne touche pas
                }

                $inserts[$lineStart] = ($inserts[$lineStart] ?? '').$indent.substr($testCode, $start, $end - $start + 1).$eol.$eol;
                [$from, $length] = SourceSpan::lines($testCode, $start, $end);
                $edits[] = [$from, $length, ''];
            }
        }
        if ([] === $edits) {
            return null;
        }
        foreach ($inserts as $position => $text) {
            $edits[] = [$position, 0, $text];
        }

        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]); // de la fin vers le début
        foreach ($edits as [$position, $length, $text]) {
            $testCode = substr_replace($testCode, $text, $position, $length);
        }

        return $testCode;
    }

    /**
     * @return list<array{0: Node\Stmt\Expression, 1: Node\Stmt}> [attente tardive, instruction d'ancrage]
     */
    private function lateExpectations(Node\Stmt\ClassMethod $method, string $sutClass, ?string $property): array
    {
        $finder = new NodeFinder();
        $body = array_values($method->stmts ?? []);

        $sutVars = [];
        foreach ($finder->findInstanceOf($body, Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)
                && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name
                && $assign->expr->class->toString() === $sutClass) {
                $sutVars[$assign->var->name] = true;
            }
        }
        $isSut = static fn (Node\Expr $r): bool => ($r instanceof Node\Expr\PropertyFetch && null !== $property
                && $r->var instanceof Node\Expr\Variable && 'this' === $r->var->name
                && $r->name instanceof Node\Identifier && $r->name->toString() === $property)
            || ($r instanceof Node\Expr\Variable && \is_string($r->name) && isset($sutVars[$r->name]));

        // Instructions de premier niveau qui appellent le SUT (ou une invocation par réflexion)
        $actions = [];
        foreach ($body as $i => $statement) {
            foreach ($finder->findInstanceOf($statement, Node\Expr\MethodCall::class) as $call) {
                $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
                if ($isSut($call->var) || \in_array($name, ['invoke', 'invokeargs'], true)) {
                    $actions[] = $i;
                    break;
                }
            }
        }
        if ([] === $actions) {
            return [];
        }
        $first = $actions[0];
        $last = $actions[array_key_last($actions)];

        // Ancre : instruction marquée « QUAND » (au plus l'action)
        $anchor = $first;
        for ($i = 0; $i <= $first; ++$i) {
            foreach ($body[$i]->getComments() as $comment) {
                if (1 === preg_match('/^\/\/\s*QUAND\b/u', $comment->getText())) {
                    $anchor = $i;
                    break 2;
                }
            }
        }

        $late = [];
        foreach ($body as $i => $statement) {
            if ($i <= $last || !$statement instanceof Node\Stmt\Expression || !$this->isMockExpectation($statement->expr, $isSut)) {
                continue;
            }
            $stubbed = $this->stubbedMethod($statement->expr);
            if (null !== $stubbed && $this->calledAfter($body, $i, $stubbed)) {
                continue; // le test appelle lui-même la méthode du mock : l'ordre est voulu
            }
            if (!$this->analyzer->movable($statement, \array_slice($body, 0, $anchor))) {
                continue;
            }
            $late[] = [$statement, $body[$anchor]];
        }

        return $late;
    }

    /**
     * Chaîne `racine->expects(M)->…` dont la racine est un mock (propriété ou variable), jamais le SUT.
     */
    private function isMockExpectation(Node\Expr $expr, \Closure $isSut): bool
    {
        $found = false;
        $n = $expr;
        for (; $n instanceof Node\Expr\MethodCall; $n = $n->var) {
            if ($n->name instanceof Node\Identifier && 'expects' === $n->name->toString()) {
                $found = true;
            }
        }

        return $found && !$isSut($n)
            && (($n instanceof Node\Expr\PropertyFetch) || ($n instanceof Node\Expr\Variable && 'this' !== $n->name));
    }

    private function stubbedMethod(Node\Expr $expr): ?string
    {
        for ($n = $expr; $n instanceof Node\Expr\MethodCall; $n = $n->var) {
            $first = $n->args[0] ?? null;
            if ($n->name instanceof Node\Identifier && 'method' === $n->name->toString()
                && $first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                return $first->value->value;
            }
        }

        return null;
    }

    /**
     * @param list<Node\Stmt> $body
     */
    private function calledAfter(array $body, int $index, string $method): bool
    {
        return null !== (new NodeFinder())->findFirst(
                \array_slice($body, $index + 1),
                static fn (Node $n): bool => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall)
                    && $n->name instanceof Node\Identifier && 0 === strcasecmp($n->name->toString(), $method),
            );
    }
}
