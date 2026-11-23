<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\StepAction;

final readonly class Step
{
    /**
     * @param string|null $target libellé visé par l'étape (chemin pour une navigation)
     * @param string|null $value  valeur saisie ou choisie
     * @param string|null $noun   « bouton », « lien », « onglet »… quand l'auteur l'a précisé
     */
    public function __construct(
        public int $index,
        public StepAction $action,
        public ?string $target,
        public ?string $value,
        public ?string $noun,
        public string $raw,
        public int $line,
    ) {
    }
}
