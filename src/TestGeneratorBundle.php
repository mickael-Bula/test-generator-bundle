<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class TestGeneratorBundle extends Bundle
{
    /**
     * Retourne le chemin racine du bundle (ici le dossier parent de src/).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}