<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

/**
 * ReachabilityService.php :
 * compute() n’atteint jamais fetch(), même via ses méthodes privées ;
 * run() l’atteint via une méthode privée
 */
final readonly class ReachabilityService
{
    public function __construct(private Gateway $gateway)
    {
    }

    public function compute(int $x): int
    {
        return $this->double($x) + $this->offset();
    }

    public function run(int $id): ?string
    {
        return $this->lookup($id);
    }

    private function lookup(int $id): ?string
    {
        return $this->gateway->fetch($id);
    }

    private function double(int $x): int
    {
        return $x * 2;
    }

    private function offset(): int
    {
        return 1;
    }
}
