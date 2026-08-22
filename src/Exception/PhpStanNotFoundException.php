<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Exception;

class PhpStanNotFoundException extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            'PHPStan n\'est pas installé sur le projet hôte.'
        );
    }
}
