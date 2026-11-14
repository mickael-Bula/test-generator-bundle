<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount;

/**
 * LoopService.php : un foreach sur un paramètre, pour la dérivation existante
 */
final readonly class LoopService
{
    public function __construct(private Gateway $gateway)
    {
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string|null>
     */
    public function fetchAll(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $found[] = $this->gateway->fetch($id);
        }

        return $found;
    }
}
