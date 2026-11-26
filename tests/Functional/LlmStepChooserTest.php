<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Choice\LlmStepChooser;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Exception\ChoiceException;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmStepChooser::class)]
final class LlmStepChooserTest extends TestCase
{
    private const SCENARIO = "# T\n\n## Étapes\n1. Cliquer sur « Modifier »\n\n## Attentes\n- L'URL est /\n";

    #[Test]
    public function testReturnsTheCandidateChosenByTheModel(): void
    {
        // ÉTANT DONNÉ un modèle qui répond « 2 » à une demande de choix entre deux candidats
        $asked = [];
        $chooser = new LlmStepChooser(static function (array $messages) use (&$asked): string {
            $asked[] = $messages;

            return '{"choice": 2}';
        });
        $scenario = (new FunctionalScenarioParser())->parse(self::SCENARIO);
        $second = new ScannedElement(tag: 'button', selector: '#b');
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [new ScannedElement(tag: 'button', selector: '#a'), $second]);

        // QUAND on demande le choix
        $chosen = $chooser->choose($scenario, $scenario->steps[0], $pending);

        // ALORS le second candidat est renvoyé, après un seul appel contenant les deux messages
        self::assertSame($second, $chosen);
        self::assertCount(1, $asked);
        self::assertSame(['system', 'user'], array_column($asked[0], 'role'));
    }

    #[Test]
    public function testReturnsNullWhenTheModelDeclines(): void
    {
        // ÉTANT DONNÉ un modèle qui répond null
        $chooser = new LlmStepChooser(static fn (array $messages): string => '{"choice": null}');
        $scenario = (new FunctionalScenarioParser())->parse(self::SCENARIO);
        $pending = new PendingDecision(1, ResolutionStatus::NotFound, [new ScannedElement(tag: 'button', selector: '#a')]);

        // QUAND on demande le choix
        // ALORS aucun candidat n'est retenu
        self::assertNull($chooser->choose($scenario, $scenario->steps[0], $pending));
    }

    #[Test]
    public function testDoesNotCallTheModelWithoutCandidates(): void
    {
        // ÉTANT DONNÉ une décision sans candidat
        $calls = 0;
        $chooser = new LlmStepChooser(static function (array $messages) use (&$calls): string {
            ++$calls;

            return '{"choice": 1}';
        });
        $scenario = (new FunctionalScenarioParser())->parse(self::SCENARIO);

        // QUAND on demande le choix
        $chosen = $chooser->choose($scenario, $scenario->steps[0], new PendingDecision(1, ResolutionStatus::NotFound, []));

        // ALORS le modèle n'est pas sollicité
        self::assertNull($chosen);
        self::assertSame(0, $calls);
    }

    #[Test]
    public function testAnUnreadableAnswerBecomesAChoiceException(): void
    {
        // ÉTANT DONNÉ un modèle qui répond par de la prose
        $chooser = new LlmStepChooser(static fn (array $messages): string => 'Le premier me semble bien.');
        $scenario = (new FunctionalScenarioParser())->parse(self::SCENARIO);
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [new ScannedElement(tag: 'button', selector: '#a')]);

        // QUAND on demande le choix
        // ALORS l'erreur est signalée (la commande l'affiche, rien n'est deviné)
        $this->expectException(ChoiceException::class);

        $chooser->choose($scenario, $scenario->steps[0], $pending);
    }
}
