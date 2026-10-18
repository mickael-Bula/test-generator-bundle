<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Quality\QualityViolation;
use Mika\TestGeneratorBundle\Quality\TestQualityAnalyzer;
use PhpParser\Node;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 95)]
final readonly class PrivateReflectionTestPreFixer implements TestPreFixerInterface
{
    public function __construct(private TestQualityAnalyzer $analyzer) {}

    public function fix(string $errorOutput, string $testCode): ?string
    {
        $analysis = $this->analyzer->analyze($testCode);

        $spans = []; // début => fin de chaque test visant une méthode non publique
        foreach ($analysis->violations as $v) {
            if (QualityViolation::PRIVATE_CALL === $v->rule && $v->node instanceof Node\Stmt\ClassMethod) {
                $spans[$v->node->getStartFilePos()] = $v->node->getEndFilePos();
            }
        }
        // Tous les tests visent des méthodes non publiques : on n'efface pas la classe, le LLM doit la réécrire
        if ([] === $spans || \count($spans) >= $analysis->testCount) {
            return null;
        }

        krsort($spans); // de la fin vers le début : les positions restent valides
        foreach ($spans as $start => $end) {
            [$from, $length] = SourceSpan::lines($testCode, $start, $end);
            $testCode = substr_replace($testCode, '', $from, $length);
        }

        return $testCode;
    }
}