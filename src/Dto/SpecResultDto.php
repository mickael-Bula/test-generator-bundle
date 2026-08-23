<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente les valeurs pour générer un fichier de spécification.
 */
readonly class SpecResultDto
{
    /**
     * @param array<int, DependencyMockDto> $dependenciesToMock
     * @param array<int, MethodSpecDto>     $methods
     */
    public function __construct(
        private string $targetClass,
        private string $testType = 'Unit',
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
     * @return array<int, DependencyMockDto>
     */
    public function getDependenciesToMock(): array
    {
        return $this->dependenciesToMock;
    }

    /**
     * @return array<int, MethodSpecDto>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }
}
