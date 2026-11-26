<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;

final readonly class PendingDecision
{
    /**
     * @param list<ScannedElement> $candidates
     * @param string|null          $path       chemin de la page sur laquelle la sonde s'est arrêtée (aide au diagnostic)
     */
    public function __construct(
        public int $stepIndex,
        public ResolutionStatus $status,
        public array $candidates,
        public ?string $path = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stepIndex' => $this->stepIndex,
            'status' => $this->status->value,
            'candidates' => array_map(static fn (ScannedElement $e): array => $e->toArray(), $this->candidates),
            'path' => $this->path,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $candidates = [];
        foreach ((array) ($data['candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $candidates[] = ScannedElement::fromArray($candidate);
            }
        }

        return new self(
            (int) ($data['stepIndex'] ?? 0),
            ResolutionStatus::from((string) ($data['status'] ?? 'not_found')),
            $candidates,
            isset($data['path']) && is_string($data['path']) && '' !== $data['path'] ? $data['path'] : null,
        );
    }
}
