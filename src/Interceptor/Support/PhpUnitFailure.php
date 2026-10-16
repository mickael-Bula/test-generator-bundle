<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final readonly class PhpUnitFailure
{
    public function __construct(
        public ?string $testClass,
        public ?string $testMethod,
        public ?string $testFile,
        public ?string $sut,
    ) {
    }
}
