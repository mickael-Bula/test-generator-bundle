<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class ResolvedStep
{
    /**
     * @param string|null $opensContainer panneau apparu après l'étape
     * @param string|null $closesContainer panneau disparu après l'étape
     * @param string|null $pathAfter nouveau chemin quand l'étape a provoqué une navigation
     */
    public function __construct(
        public int $stepIndex,
        public ?Target $target,
        public ?string $optionValue,
        public ?string $opensContainer,
        public ?string $closesContainer,
        public ?string $pathAfter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stepIndex' => $this->stepIndex,
            'target' => $this->target?->toArray(),
            'optionValue' => $this->optionValue,
            'opensContainer' => $this->opensContainer,
            'closesContainer' => $this->closesContainer,
            'pathAfter' => $this->pathAfter,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['stepIndex'] ?? 0),
            isset($data['target']) && is_array($data['target']) ? Target::fromArray($data['target']) : null,
            isset($data['optionValue']) ? (string) $data['optionValue'] : null,
            isset($data['opensContainer']) ? (string) $data['opensContainer'] : null,
            isset($data['closesContainer']) ? (string) $data['closesContainer'] : null,
            isset($data['pathAfter']) ? (string) $data['pathAfter'] : null,
        );
    }
}
