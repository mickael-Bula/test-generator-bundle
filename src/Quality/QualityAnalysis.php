<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

final readonly class QualityAnalysis
{
    /** @param list<QualityViolation> $violations */
    public function __construct(public array $violations, public int $testCount)
    {
    }
}