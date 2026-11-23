<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class Panel
{
    public function __construct(
        public string $selector,
        public ?string $title,
        public bool $visible,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['selector'] ?? ''),
            isset($data['title']) && '' !== $data['title'] ? (string) $data['title'] : null,
            (bool) ($data['visible'] ?? false),
        );
    }
}
