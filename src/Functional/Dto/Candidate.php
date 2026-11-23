<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class Candidate
{
    public function __construct(
        public ScannedElement $element,
        public int $score,
        public string $reason,
    ) {
    }
}
