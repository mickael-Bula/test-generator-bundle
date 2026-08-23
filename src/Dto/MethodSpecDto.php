<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente la spécification complète pour une méthode de la classe cible.
 */
readonly class MethodSpecDto
{
    /**
     * @param array<int, DataProviderDto> $dataProviders
     * @param array<int, TestCaseDto>     $testCases
     */
    public function __construct(
        private string $name,
        private array $dataProviders = [],
        private array $testCases = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<int, DataProviderDto>
     */
    public function getDataProviders(): array
    {
        return $this->dataProviders;
    }

    /**
     * @return array<int, TestCaseDto>
     */
    public function getTestCases(): array
    {
        return $this->testCases;
    }
}
