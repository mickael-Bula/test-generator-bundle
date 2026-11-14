<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

/**
 * Gateway.php est la dépendance mockée. Seule sa méthode fetch() est observée.
 */
class Gateway
{
    public function fetch(int $id): ?string
    {
        return null;
    }
}
