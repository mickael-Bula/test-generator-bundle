<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 75)]
final readonly class VoidAssignmentPreFixer implements TestPreFixerInterface
{
    public function __construct(
        private Parser $parser,
        private VoidAssignmentFinder $finder,
    ) {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        // null reçu par un argument, appel sur null, ou PHPStan : « Result of method … (void) is used »
        if (!str_contains($errorOutput, 'null given') && !str_contains($errorOutput, 'on null') && !str_contains($errorOutput, '(void) is used')) {
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
        foreach ($this->finder->find($stmts) as $f) {
            $statement = $f['statement'];
            $indent = SourceSpan::indentAt($testCode, $statement->getStartFilePos());

            $lines = [sprintf('$%s = %s;', $f['variable'], $this->text($testCode, $f['new']))];
            foreach ($f['calls'] as $call) {
                $args = $call->args;
                $lines[] = sprintf(
                    '$%s->%s(%s);',
                    $f['variable'],
                    $call->name->toString(),
                    [] === $args ? '' : substr($testCode, $args[0]->getStartFilePos(), $args[array_key_last($args)]->getEndFilePos() - $args[0]->getStartFilePos() + 1),
                );
            }
            $edits[$statement->getStartFilePos()] = [$statement->getEndFilePos(), implode("\n" . $indent, $lines)];
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

    private function text(string $code, Node $node): string
    {
        return substr($code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }
}