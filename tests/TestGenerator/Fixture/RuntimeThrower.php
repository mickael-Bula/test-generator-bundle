<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final class RuntimeThrower
{
    public function run(): void
    {
        throw new \RuntimeException("autre");
    }
}
