<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

readonly class ResolvedClass
{
    public function __construct(
        public string $className,
        public string $filePath,
    ) {
    }

    /**
     * Extrait et retourne le nom court de la classe (ex : "VatCalculator" pour "App\Service\VatCalculator").
     */
    public function getShortClassName(): string
    {
        $parts = explode('\\', $this->className);

        return end($parts);
    }
}
