<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final class Consumer
{
    public function __construct(private readonly FinalDependency $dependency)
    {
    }
}
