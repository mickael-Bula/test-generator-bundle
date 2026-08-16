<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

final readonly class TestTargetPath
{
    public function __construct(
        public string $targetNamespace,
        public string $targetDirectory,
        public string $filePath,
    ) {
    }
}
