<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

interface TestPromptBuilderInterface
{
    public function supports(string $type): bool;

    /**
     * @return array{system: string, user: string}
     */
    public function buildPrompt(
        string $classCode,
        string $fqcn,
        string $className,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
    ): array;
}
