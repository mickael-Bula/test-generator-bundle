<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

/**
 * BuildingService.php : constructeur non trivial. Un collaborateur construit ici pourrait recevoir le mock
 */
final class BuildingService
{
    private \ArrayObject $bag;

    public function __construct(private readonly Gateway $gateway)
    {
        $this->bag = new \ArrayObject([$gateway]);
    }

    public function compute(): int
    {
        return $this->bag->count();
    }
}
