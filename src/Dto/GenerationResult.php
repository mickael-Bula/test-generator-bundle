<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

final readonly class GenerationResult
{
    /**
     * @param list<array{test: string, attempts: int, report: string, code: string}> $removed
     */
    public function __construct(
        public string $code,
        public array $removed = [],
        public ?string $reviewPath = null,
    ) {
    }
}
