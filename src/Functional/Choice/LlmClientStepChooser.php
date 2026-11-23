<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Choice;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;

/**
 * Choix par le LLM configuré dans le .env (LLM_PROVIDER, LLM_MODEL), via LlmClientInterface::call().
 */
final class LlmClientStepChooser implements StepChooserInterface
{
    private readonly LlmStepChooser $inner;

    public function __construct(LlmClientFactory $factory)
    {
        $this->inner = new LlmStepChooser(
            static fn (array $messages): string => $factory->getClient()->call($messages, $factory->getDefaultModel()),
        );
    }

    public function choose(FunctionalScenario $scenario, Step $step, PendingDecision $pending): ?ScannedElement
    {
        return $this->inner->choose($scenario, $step, $pending);
    }
}
