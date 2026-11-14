<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

final class InheritingService extends BaseService
{
    public function __construct(private readonly Gateway $gateway)
    {
    }

    public function compute(): int
    {
        return $this->helper();
    }
}
