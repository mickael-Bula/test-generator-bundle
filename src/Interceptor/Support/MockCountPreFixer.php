<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Node;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Corrige un `once()` / `exactly(N)` seulement quand le code du SUT et PHPUnit s'accordent sur le bon nombre.
 */
#[AsTaggedItem(priority: 42)]
final readonly class MockCountPreFixer implements TestPreFixerInterface
{
    public function __construct(private MockCountAnalyzer $analyzer) {}

    public function fix(string $errorOutput, string $testCode): ?string
    {
        $edits = []; // début => [fin, remplacement]
        foreach ($this->analyzer->analyze($errorOutput, $testCode) as $f) {
            if (null === $f->matcher || null === $f->limit || null === $f->derived || $f->derived < 1 || $f->derived === $f->limit) {
                continue;
            }
            // Preuve : PHPUnit a mesuré le nombre que le code prévoit ; pour « trop d'appels », le code en prévoit davantage
            $proved = 'wrong-count' === $f->kind ? $f->observed === $f->derived : $f->derived > $f->limit;
            $m = $f->matcher;
            if (!$proved || !($m instanceof Node\Expr\StaticCall || $m instanceof Node\Expr\MethodCall) || !$m->name instanceof Node\Identifier) {
                continue;
            }

            $start = $m->getStartFilePos();
            $prefix = substr($testCode, $start, $m->name->getStartFilePos() - $start); // « self:: » ou « $this-> »
            $edits[$start] = [$m->getEndFilePos(), $prefix . (1 === $f->derived ? 'once()' : 'exactly(' . $f->derived . ')')];
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
}