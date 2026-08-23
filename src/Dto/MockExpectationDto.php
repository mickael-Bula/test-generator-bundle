<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente une attente ou un comportement configuré sur un mock de dépendance.
 */
readonly class MockExpectationDto
{
    public function __construct(
        private string $dependency,
        private string $method,
        private mixed $willReturns = null,
        private ?string $willThrow = null,
    ) {
    }

    public function getDependency(): string
    {
        return $this->dependency;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getWillReturns(): mixed
    {
        return $this->willReturns;
    }

    public function getWillThrow(): ?string
    {
        return $this->willThrow;
    }
}
