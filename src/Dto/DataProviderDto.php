<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente un `@dataProvider` de test.
 */
readonly class DataProviderDto
{
    /**
     * @param array<int, string>     $dataSetKeys Liste des noms de variables/paramètres attendus
     * @param array<int, DataSetDto> $dataSets    Liste des jeux de données
     */
    public function __construct(
        private string $providerName,
        private string $description = '',
        private array $dataSetKeys = [],
        private array $dataSets = [],
    ) {
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<int, string>
     */
    public function getDataSetKeys(): array
    {
        return $this->dataSetKeys;
    }

    /**
     * @return array<int, DataSetDto>
     */
    public function getDataSets(): array
    {
        return $this->dataSets;
    }
}
