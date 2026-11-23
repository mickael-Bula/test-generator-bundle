<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class ScenarioContext
{
    public function __construct(
        public ?string $authStrategy = null,
        public ?string $authProfile = null,
        public string $startPath = '/',
    ) {
    }
}
