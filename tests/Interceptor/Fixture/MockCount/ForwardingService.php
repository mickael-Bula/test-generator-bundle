<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

final readonly class ForwardingService
{
    public function __construct(private Gateway $gateway)
    {
    }

    public function quantity(int $id): int
    {
        $value = $this->snapshot($id);

        if (null === $value) {
            return 0;
        }

        return \strlen($value);
    }

    public function twice(int $id): int
    {
        return \strlen((string) $this->snapshot($id)) + \strlen((string) $this->snapshot($id));
    }

    public function guarded(int $id): int
    {
        if ($id < 0) {
            return 0;
        }

        return \strlen((string) $this->snapshot($id));
    }

    public function conditional(int $id): int
    {
        if ($id > 0) {
            $this->snapshot($id);
        }

        return $id;
    }

    public function snapshot(int $id): ?string
    {
        return $this->label($this->gateway->fetch($id));
    }

    private function label(?string $value): ?string
    {
        return $value;
    }
}
