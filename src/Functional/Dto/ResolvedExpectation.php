<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class ResolvedExpectation
{
    /**
     * @param string|null $panelSelector sélecteur du panneau visé par l'attente, quand elle en vise un
     */
    public function __construct(
        public int $expectationIndex,
        public ?string $panelSelector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['expectationIndex' => $this->expectationIndex, 'panelSelector' => $this->panelSelector];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['expectationIndex'] ?? 0),
            isset($data['panelSelector']) ? (string) $data['panelSelector'] : null,
        );
    }
}
