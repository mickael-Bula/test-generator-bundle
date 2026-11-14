<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

final class TraitService
{
    use ComputesTrait;

    public function __construct(private readonly Gateway $gateway)
    {
    }

    public function compute(): int
    {
        return $this->fromTrait();
    }
}
