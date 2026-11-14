<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

/**
 * UnsafeService.php : les cas où la preuve doit être refusée,
 * plus passScalar() en contre-exemple (un scalaire passé ne peut pas contenir le mock).
 */
final class UnsafeService
{
    private int $factor = 2;

    public function __construct(private readonly Gateway $gateway)
    {
    }

    public function leakThis(): int
    {
        return spl_object_id($this);
    }

    public function passObject(): int
    {
        return \count([$this->gateway]);
    }

    public function byName(string $method): mixed
    {
        return $this->$method();
    }

    public function byCallable(): mixed
    {
        return call_user_func('strtoupper', 'a');
    }

    public function passScalar(): int
    {
        return $this->scale($this->factor);
    }

    private function scale(int $value): int
    {
        return $value * 3;
    }
}
