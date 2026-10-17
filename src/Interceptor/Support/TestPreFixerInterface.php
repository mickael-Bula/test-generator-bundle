<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

interface TestPreFixerInterface
{
    /**
     * Corrige de manière déterministe.
     */
    public function fix(string $errorOutput, string $testCode): ?string;
}
