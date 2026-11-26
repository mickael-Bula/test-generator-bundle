<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Choice\LlmClientStepChooser;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Llm\LlmClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmClientStepChooser::class)]
final class LlmClientStepChooserTest extends TestCase
{
    #[Test]
    public function testCallsTheConfiguredClientWithTheDefaultModel(): void
    {
        // ÉTANT DONNÉ un client LLM factice, enregistré dans la fabrique avec un modèle par défaut
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('supports')->willReturn(true);
        $client->expects(self::once())
            ->method('call')
            ->with(
                self::callback(static fn (array $messages): bool => ['system', 'user'] === array_column($messages, 'role')),
                'modele-de-test',
            )
            ->willReturn('{"choice": 2}');
        $chooser = new LlmClientStepChooser(new LlmClientFactory([$client], 'gemini', 'modele-de-test'));
        $scenario = (new FunctionalScenarioParser())->parse("# T\n\n## Étapes\n1. Cliquer sur « Modifier »\n\n## Attentes\n- L'URL est /\n");
        $second = new ScannedElement(tag: 'button', selector: '#b');
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [new ScannedElement(tag: 'button', selector: '#a'), $second]);

        // QUAND on demande le choix
        $chosen = $chooser->choose($scenario, $scenario->steps[0], $pending);

        // ALORS la réponse du client désigne le second candidat
        self::assertSame($second, $chosen);
    }
}
