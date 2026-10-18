<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * « actually called 0 times » alors que le test déclare `expects()` APRÈS l'appel du SUT : on le remonte avant le QUAND.
 */
#[AsTaggedItem(priority: 45)]
final readonly class LateExpectationPreFixer implements TestPreFixerInterface
{
    public function __construct(private MockCountAnalyzer $analyzer) {}

    public function fix(string $errorOutput, string $testCode): ?string
    {
        $edits = []; // [position, longueur, texte]
        $inserts = [];

        foreach ($this->analyzer->analyze($errorOutput, $testCode) as $f) {
            if ('wrong-count' !== $f->kind || 0 !== $f->observed || null === $f->expectation || null === $f->moveBefore) {
                continue;
            }
            $start = $f->expectation->getStartFilePos();
            $end = $f->expectation->getEndFilePos();

            // Insertion avant le premier commentaire de l'ancre (le marqueur « QUAND »), à l'indentation de la ligne
            $anchor = $f->moveBefore->getStartFilePos();
            foreach ($f->moveBefore->getComments() as $comment) {
                $anchor = min($anchor, $comment->getStartFilePos());
            }
            $newline = strrpos(substr($testCode, 0, $anchor), "\n");
            $lineStart = false === $newline ? 0 : $newline + 1;
            $indent = substr($testCode, $lineStart, $anchor - $lineStart);
            if ('' !== trim($indent)) {
                continue; // du code précède l'ancre sur sa ligne : on ne touche pas
            }

            $inserts[$lineStart] = ($inserts[$lineStart] ?? '') . $indent . substr($testCode, $start, $end - $start + 1) . "\n\n";
            [$from, $length] = SourceSpan::lines($testCode, $start, $end);
            $edits[] = [$from, $length, ''];
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
}