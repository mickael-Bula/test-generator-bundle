<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Choice;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;

/**
 * Ne choisit jamais : la commande s'arrête et liste les candidats (option --no-llm).
 */
final class NullStepChooser implements StepChooserInterface
{
    public function choose(FunctionalScenario $scenario, Step $step, PendingDecision $pending): ?ScannedElement
    {
        return null;
    }
}
