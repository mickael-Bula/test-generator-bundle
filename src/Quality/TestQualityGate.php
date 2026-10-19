<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

final readonly class TestQualityGate
{
    public function __construct(private TestQualityAnalyzer $analyzer)
    {
    }

    /** Section au format PHPUnit (« 1) Classe::test » puis le message), ou null si le test est conforme. */
    public function report(string $testCode, ?string $baseline = null): ?string
    {
        $violations = $this->analyzer->analyze($testCode, $baseline)->violations;
        if ([] === $violations) {
            return null;
        }

        $lines = ['ERREUR DE QUALITÉ (ANALYSE STATIQUE DU TEST) :', ''];
        foreach ($violations as $i => $v) {
            $lines[] = sprintf('%d) %s::%s', $i + 1, $v->testClass, $v->test);
            $lines[] = sprintf('[%s] %s', $v->rule, $v->message);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}