<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\ResolutionMethod;

/**
 * Comment une étape a été résolue, pour le compte rendu de la commande.
 */
final readonly class StepReport
{
    public function __construct(
        public int $index,
        public string $raw,
        public ResolutionMethod $method,
        public ?string $selector,
        public ?string $container,
        public bool $fragile,
    ) {
    }
}
