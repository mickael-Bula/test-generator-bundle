<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final class ExportableDto
{
    /** @param list<self> $items */
    public function __construct(
        private string $name,
        private array $items,
        private Status $status,
        private ?int $count = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
