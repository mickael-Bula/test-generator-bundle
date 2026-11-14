<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

class BaseService
{
    protected function helper(): int
    {
        return 1;
    }
}
