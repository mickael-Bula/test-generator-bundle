<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Choice;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;

/**
 * Tranche une étape que les règles n'ont pas su résoudre (plusieurs éléments à égalité, ou aucun).
 *
 * L'élément renvoyé est rejoué par la sonde, qui le revalide en l'exécutant : un mauvais choix
 * se voit donc (clic impossible, attente non constatée), il ne passe pas en silence.
 */
interface StepChooserInterface
{
    /**
     * @return ScannedElement|null l'un des candidats de $pending, ou null si aucun ne convient
     */
    public function choose(FunctionalScenario $scenario, Step $step, PendingDecision $pending): ?ScannedElement;
}
