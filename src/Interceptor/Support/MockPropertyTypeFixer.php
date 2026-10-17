<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

final readonly class MockPropertyTypeFixer implements TestPreFixerInterface
{
    private const MOCK_OBJECT = 'PHPUnit\Framework\MockObject\MockObject';
    private const PATTERN = '/(?:Ligne|Line)\s+(?<line>\d+)\s*:\s*Call to an undefined method (?<class>[\w\\\\]+)::(?<method>expects|method)\(\)/';

    public function __construct(private Parser $parser) {}

    /** @return string|null le test corrigé, ou null si rien n'a pu être corrigé */
    public function fix(string $phpStanOutput, string $code): ?string
    {
        preg_match_all(self::PATTERN, $phpStanOutput, $errors, PREG_SET_ORDER);
        if ([] === $errors) {
            return null;
        }

        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $finder = new NodeFinder();
        $edits = []; // position de début => [position de fin, remplacement]

        foreach ($errors as $e) {
            $line = (int) $e['line'];
            if (method_exists($e['class'], $e['method'])) {
                continue; // la méthode existe vraiment : ce n'est pas un problème de type de mock
            }

            // PHPStan rapporte la ligne de l'appel ; selon les versions, début de l'expression ou nom de la méthode
            $call = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall
                && $n->name instanceof Node\Identifier
                && $n->name->toString() === $e['method']
                && ($n->name->getStartLine() === $line || $n->getStartLine() === $line)
                && $n->var instanceof Node\Expr\PropertyFetch
                && $n->var->var instanceof Node\Expr\Variable
                && 'this' === $n->var->var->name
                && $n->var->name instanceof Node\Identifier);
            if (null === $call) {
                continue;
            }

            $propName = $call->var->name->toString();
            $property = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\Property
                && [] !== array_filter($n->props, static fn ($p): bool => $p->name->toString() === $propName));

            $type = $property?->type;
            $inner = $type instanceof Node\NullableType ? $type->type : $type; // `?X` devient `X&MockObject`
            if (!$inner instanceof Node\Name || $inner->toString() !== $e['class']) {
                continue; // type déjà intersection/union, ou propriété non typée : on laisse au LLM
            }

            $final = class_exists($e['class']) && (new \ReflectionClass($e['class']))->isFinal();

            $edits[$type->getStartFilePos()] = [
                $type->getEndFilePos(),
                // Classe `final` : l'intersection serait `never`. On déclare donc une union à la place de l'intersection.
                $final ? '\\' . self::MOCK_OBJECT . '|\\' . $e['class'] : '\\' . $e['class'] . '&\\' . self::MOCK_OBJECT,
            ];
        }

        if ([] === $edits) {
            return null;
        }

        krsort($edits); // de la fin vers le début : les positions restent valides
        foreach ($edits as $start => [$end, $replacement]) {
            $code = substr_replace($code, $replacement, $start, $end - $start + 1);
        }

        return $code;
    }
}