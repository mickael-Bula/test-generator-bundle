<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

readonly class Dependency
{
    public function __construct(public string $name, public int $count)
    {
    }

    public function setWhen(\DateTimeInterface $when): void
    {
    }

    public function setItems(array $items): void
    {
    }

    public function setContract(Contract $contract): void
    {
    }

    public function setOwner(self $owner): void
    {
    }

    public function useMutable(Mutable $mutable): void
    {
    }
}
