<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final class Thrower
{
    public function run(int $id): void
    {
        throw new \LogicException(sprintf("Le dossier #%d n'est rattaché à aucun utilisateur.", $id));
    }
}
