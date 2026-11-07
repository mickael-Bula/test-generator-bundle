<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

final class QuantityCalculator
{
    public function quantity(int $capital, float $price): int
    {
        if ($price <= 0.0) {
            throw new \InvalidArgumentException('Le prix cible ne peut pas être égal à zéro.');
        }

        return (int) round($capital / $price);
    }

    public function failing(int $id): void
    {
        throw new \LogicException(sprintf("Impossible de calculer le gap : la position #%d n'est rattachée à aucun utilisateur.", $id));
    }

    public function maybe(bool $flag): ?int
    {
        return $flag ? 1 : null;
    }
}
