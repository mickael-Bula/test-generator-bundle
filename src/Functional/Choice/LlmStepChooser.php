<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Choice;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;

/**
 * Choix par LLM, indépendant du client : l'appel est une fonction « messages → texte ».
 * LlmClientStepChooser la branche sur LlmClientFactory.
 */
final class LlmStepChooser implements StepChooserInterface
{
    /**
     * @param \Closure(list<array{role: string, content: string}>): string $ask
     */
    public function __construct(
        private readonly \Closure $ask,
        private readonly ChoicePromptBuilder $prompts = new ChoicePromptBuilder(),
        private readonly ChoiceResponseParser $parser = new ChoiceResponseParser(),
    ) {
    }

    public function choose(FunctionalScenario $scenario, Step $step, PendingDecision $pending): ?ScannedElement
    {
        if ([] === $pending->candidates) {
            return null;
        }

        $response = ($this->ask)($this->prompts->messages($scenario, $step, $pending));
        $index = $this->parser->parse($response, count($pending->candidates));

        return null === $index ? null : $pending->candidates[$index];
    }
}
