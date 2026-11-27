<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Choice\StepChooserInterface;
use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;

/**
 * Choix de test : retient le candidat d'indice donné (ou aucun), ou lève une erreur.
 */
final class FakeStepChooser implements StepChooserInterface
{
    public int $calls = 0;

    public function __construct(private readonly ?int $pick = 0, private readonly ?\Throwable $failure = null)
    {
    }

    public function choose(FunctionalScenario $scenario, Step $step, PendingDecision $pending): ?ScannedElement
    {
        ++$this->calls;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return null === $this->pick ? null : $pending->candidates[$this->pick];
    }
}
