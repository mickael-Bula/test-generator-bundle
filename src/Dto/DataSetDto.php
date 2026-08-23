<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente un jeu de données unique fourni par un `@dataProvider`.
 */
readonly class DataSetDto
{
    /**
     * @param array<string, mixed> $providedValues Valeurs fournies (clé = nom du paramètre, valeur = valeur injectée)
     */
    public function __construct(
        private string $label,
        private array $providedValues = [],
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProvidedValues(): array
    {
        return $this->providedValues;
    }
}
