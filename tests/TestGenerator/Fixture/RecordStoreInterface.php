<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

interface RecordStoreInterface
{
    /**
     * @param array<string, mixed> $record
     */
    public function persist(array $record): void;
}
