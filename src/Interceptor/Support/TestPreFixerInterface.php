<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

interface TestPreFixerInterface
{
    /**
     * @return string|null le test corrigé, ou null si ce correcteur ne s'applique pas à cette sortie
     */
    public function fix(string $errorOutput, string $testCode): ?string;
}