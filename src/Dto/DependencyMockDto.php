<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente lune valeur de retour du tableau des dépendances à mocker (dependenciesToMock).
 */
readonly class DependencyMockDto
{
    public function __construct(
        private string $class,
        private string $propertyName,
    ) {
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }
}
