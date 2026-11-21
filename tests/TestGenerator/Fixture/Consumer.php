<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final readonly class Consumer
{
    public function __construct(private FinalDependency $dependency)
    {
    }
}
