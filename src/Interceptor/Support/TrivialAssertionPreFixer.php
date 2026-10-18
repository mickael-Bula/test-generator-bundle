<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Quality\QualityViolation;
use Mika\TestGeneratorBundle\Quality\TestQualityAnalyzer;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 30)]
final readonly class TrivialAssertionPreFixer implements TestPreFixerInterface
{
    public function __construct(private TestQualityAnalyzer $analyzer) {}

    public function fix(string $errorOutput, string $testCode): ?string
    {
        $spans = [];
        foreach ($this->analyzer->analyze($testCode)->violations as $v) {
            if (QualityViolation::TRIVIAL_ASSERTION === $v->rule && null !== $v->node) {
                $spans[$v->node->getStartFilePos()] = $v->node->getEndFilePos();
            }
        }
        if ([] === $spans) {
            return null;
        }

        krsort($spans);
        foreach ($spans as $start => $end) {
            // Commentaire de fin de ligne (« // Aucune assertion nécessaire ») : il disparaît avec l'instruction
            if (1 === preg_match('/\G[ \t]*\/\/[^\r\n]*/', $testCode, $m, 0, $end + 1)) {
                $end += \strlen($m[0]);
            }
            [$from, $length] = SourceSpan::lines($testCode, $start, $end);
            $testCode = substr_replace($testCode, '', $from, $length);
        }

        return $testCode;
    }
}