<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

/**
 * MagicService.php : méthode appelée absente de la classe
 */
final readonly class MagicService
{
    public function __construct(private Gateway $gateway)
    {
    }

    public function compute(): int
    {
        return $this->undeclared();
    }

    /**
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): int
    {
        return null === $this->gateway->fetch(1) ? 0 : 1;
    }
}
