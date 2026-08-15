<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

readonly class SpecResultDto
{
    /**
     * @param array<string, mixed>             $dependenciesToMock
     * @param array<int, array<string, mixed>> $methods
     */
    public function __construct(
        private string $targetClass,
        private string $testType,
        private array $dependenciesToMock = [],
        private array $methods = [],
    ) {
    }

    public function getTargetClass(): string
    {
        return $this->targetClass;
    }

    public function getTestType(): string
    {
        return $this->testType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDependenciesToMock(): array
    {
        return $this->dependenciesToMock;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Reconvertit le DTO sous forme de tableau associatif pour le SpecMarkdownRenderer.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'targetClass' => $this->targetClass,
            'testType' => $this->testType,
            'dependenciesToMock' => $this->dependenciesToMock,
            'methods' => $this->methods,
        ];
    }
}
