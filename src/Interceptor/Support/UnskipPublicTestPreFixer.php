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
#[AsTaggedItem(priority: 90)]
final readonly class UnskipPublicTestPreFixer implements TestPreFixerInterface
{
    public function __construct(
        private SkippedTestScanner $scanner,
        private CoveredClassReader $coveredClass,
        private Parser $parser,
        private SutPropertyFinder $sutPropertyFinder,
    ) {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        $class = $this->coveredClass->read($testCode);
        if (null === $class || !class_exists($class) || null === $property = $this->sutProperty($testCode, $class)) {
            return null;
        }

        $edits = []; // position de début => [position de fin, remplacement]
        foreach ($this->scanner->scan($testCode, new \ReflectionClass($class)) as $hit) {
            if (!$hit['target']->isPublic() || $hit['target']->isStatic()) {
                continue;
            }
            if (null !== $call = $this->call($hit, $property)) {
                $edits[$hit['statement']->getStartFilePos()] = [$hit['statement']->getEndFilePos(), $call];
            }
        }
        if ([] === $edits) {
            return null;
        }

        krsort($edits);
        foreach ($edits as $start => [$end, $replacement]) {
            $testCode = substr_replace($testCode, $replacement, $start, $end - $start + 1);
        }

        return $testCode;
    }

    /** @param array{test: Node\Stmt\ClassMethod, statement: Node\Stmt\Expression, target: \ReflectionMethod} $hit */
    private function call(array $hit, string $property): ?string
    {
        // Variables déjà assignées dans le test avant l'instruction ignorée
        $locals = [];
        foreach ((new NodeFinder())->findInstanceOf($hit['test']->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)
                && $assign->getStartFilePos() < $hit['statement']->getStartFilePos()) {
                $locals[$assign->var->name] = true;
            }
        }

        $args = [];
        foreach ($hit['target']->getParameters() as $p) {
            if (isset($locals[$p->getName()])) {
                $args[] = '$' . $p->getName();
            } elseif ($p->isDefaultValueAvailable() || $p->isVariadic()) {
                break; // les paramètres restants sont facultatifs.
            } else {
                return null; // paramètre requis sans variable correspondante : le LLM complète
            }
        }

        $assign = '';
        $type = $hit['target']->getReturnType();
        if (null !== $type && !\in_array((string) $type, ['void', 'never'], true)) {
            $name = !isset($locals['result']) ? 'result' : (!isset($locals['actual']) ? 'actual' : null);
            $assign = null === $name ? '' : '$' . $name . ' = ';
        }

        return sprintf('%s$this->%s->%s(%s);', $assign, $property, $hit['target']->getName(), implode(', ', $args));
    }

    /**
     * Nom de la propriété du test typée avec le SUT (`$sut`, ou autre).
     */
    private function sutProperty(string $code, string $sutClass): ?string
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $this->sutPropertyFinder->find($traverser->traverse($stmts), $sutClass);
    }
}