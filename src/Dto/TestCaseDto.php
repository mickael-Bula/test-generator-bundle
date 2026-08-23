<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente un cas de test unique pour une méthode.
 */
readonly class TestCaseDto
{
    /**
     * @param array<string, mixed>           $inputs           Valeurs d'entrées si le test n'utilise pas de data provider
     * @param array<int, MockExpectationDto> $mockExpectations Attentes configurées sur les mocks
     */
    public function __construct(
        private string $title,
        private string $type = 'NOMINAL',
        private string $description = '',
        private bool $usesDataProvider = false,
        private ?string $dataProviderName = null,
        private array $inputs = [],
        private array $mockExpectations = [],
        private ?ExpectedBehaviorDto $expectedBehavior = null,
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isUsesDataProvider(): bool
    {
        return $this->usesDataProvider;
    }

    public function getDataProviderName(): ?string
    {
        return $this->dataProviderName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    /**
     * @return array<int, MockExpectationDto>
     */
    public function getMockExpectations(): array
    {
        return $this->mockExpectations;
    }

    public function getExpectedBehavior(): ?ExpectedBehaviorDto
    {
        return $this->expectedBehavior;
    }
}
