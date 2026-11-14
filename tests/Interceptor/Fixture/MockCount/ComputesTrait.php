<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

trait ComputesTrait
{
    private function fromTrait(): int
    {
        return 1;
    }
}
