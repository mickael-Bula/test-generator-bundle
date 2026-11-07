<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

interface Contract
{
    public function execute(Dependency $dependency): void;
}
