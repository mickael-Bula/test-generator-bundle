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
            $target = $this->target($f);
            $m = $f->matcher;
            if (null === $target || !($m instanceof Node\Expr\StaticCall || $m instanceof Node\Expr\MethodCall) || !$m->name instanceof Node\Identifier) {
                continue;
            }

            $start = $m->getStartFilePos();
            $prefix = substr($testCode, $start, $m->name->getStartFilePos() - $start); // « self:: » ou « $this-> »
            $replacement = match ($target) {
                0 => 'never()',
                1 => 'once()',
                default => 'exactly(' . $target . ')',
            };
            $edits[$start] = [$m->getEndFilePos(), $prefix . $replacement];
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

    /**
     * Le nombre d'appels prouvé, ou null. Zéro : PHPUnit a mesuré 0 appel ET aucun chemin du SUT n'atteint la méthode.
     * Une boucle sur un tableau vide (derived = 0, sans preuve de chemin) reste exclue : les données du test sont alors en cause.
     */
    private function target(MockCountFinding $f): ?int
    {
        if (null !== $f->neverCalled) {
            return 'wrong-count' === $f->kind && 0 === $f->observed ? 0 : null;
        }
        if (null === $f->limit || null === $f->derived || $f->derived < 1 || $f->derived === $f->limit) {
            return null;
        }
        // Preuve : PHPUnit a mesuré le nombre que le code prévoit ; pour « trop d'appels », le code en prévoit davantage
        $proved = 'wrong-count' === $f->kind ? $f->observed === $f->derived : $f->derived > $f->limit;

        return $proved ? $f->derived : null;
    }
}
