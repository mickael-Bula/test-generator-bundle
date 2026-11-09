<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final class VerdictConsumer
{
    public function consume(Verdict $verdict): string
    {
        return $verdict->value;
    }
}
