<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

final readonly class QualityAnalysis
{
    /**
     * Classe DTO garantissant que l'état de l'analyse ou de la violation
     * reste strictement identique tout au long de l'exécution.
     *
     * @param list<QualityViolation> $violations
     */
    public function __construct(public array $violations, public int $testCount)
    {
    }
}