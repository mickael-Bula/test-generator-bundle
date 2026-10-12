<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

final readonly class DatabaseInfosDto
{
    public function __construct(
        public string $dbName,
        public string $platform,
        public bool $dbExists,
        public bool $hasMigrations,
        public bool $hasSuffix,
    ) {
    }
}