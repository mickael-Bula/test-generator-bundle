<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Choice\ChoicePromptBuilder;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChoicePromptBuilder::class)]
final class ChoicePromptBuilderTest extends TestCase
{
    private ChoicePromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ChoicePromptBuilder();
    }

    #[Test]
    public function testBuildsASystemAndAUserMessage(): void
    {
        // ÉTANT DONNÉ un scénario de deux étapes dont la première est ambiguë entre deux boutons
        $scenario = (new FunctionalScenarioParser())->parse("# Modifier l'objectif\n\n## Étapes\n1. Cliquer sur « Modifier »\n2. Cliquer sur le lien « Paramètres »\n\n## Attentes\n- L'URL est /\n");
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [
            new ScannedElement(tag: 'button', targetTitle: 'Modifier l\'objectif Core', selector: '#a'),
            new ScannedElement(tag: 'button', targetTitle: 'Modifier l\'objectif Satellite', selector: '#b'),
        ]);

        // QUAND on construit les messages
        $messages = $this->builder->messages($scenario, $scenario->steps[0], $pending);

        // ALORS le système impose la forme de la réponse et l'utilisateur reçoit le contexte et les candidats numérotés
        self::assertSame(['system', 'user'], array_column($messages, 'role'));
        self::assertStringContainsString('{"choice": N}', $messages[0]['content']);
        self::assertStringContainsString('{"choice": null}', $messages[0]['content']);
        $user = $messages[1]['content'];
        self::assertStringContainsString('Scénario : Modifier l\'objectif', $user);
        self::assertStringContainsString('>> 1. Cliquer sur « Modifier »', $user);
        self::assertStringContainsString('   2. Cliquer sur le lien « Paramètres »', $user);
        self::assertStringContainsString('Plusieurs éléments correspondent', $user);
        self::assertStringContainsString('1. <button> · titre du panneau ouvert « Modifier l\'objectif Core » · sélecteur #a', $user);
        self::assertStringContainsString('2. <button> · titre du panneau ouvert « Modifier l\'objectif Satellite » · sélecteur #b', $user);
    }

    #[Test]
    public function testWordsTheProblemDifferentlyWhenNothingMatches(): void
    {
        // ÉTANT DONNÉ une étape introuvable
        $scenario = (new FunctionalScenarioParser())->parse("# T\n\n## Étapes\n1. Cliquer sur « Zzz »\n\n## Attentes\n- L'URL est /\n");
        $pending = new PendingDecision(1, ResolutionStatus::NotFound, [new ScannedElement(tag: 'a', text: 'Accueil', selector: '#home')]);

        // QUAND on construit les messages
        $user = $this->builder->messages($scenario, $scenario->steps[0], $pending)[1]['content'];

        // ALORS le problème est décrit comme une absence de correspondance exacte
        self::assertStringContainsString('Aucun élément ne porte exactement ce libellé', $user);
        self::assertStringNotContainsString('Plusieurs éléments', $user);
    }

    #[Test]
    public function testTruncatesALongListOfSteps(): void
    {
        // ÉTANT DONNÉ un scénario de quinze étapes
        $steps = implode("\n", array_map(static fn (int $i): string => sprintf('%d. Cliquer sur « Bouton %d »', $i, $i), range(1, 15)));
        $scenario = (new FunctionalScenarioParser())->parse("# Long\n\n## Étapes\n{$steps}\n\n## Attentes\n- L'URL est /\n");
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [new ScannedElement(tag: 'button', selector: '#a')]);

        // QUAND on construit les messages
        $user = $this->builder->messages($scenario, $scenario->steps[0], $pending)[1]['content'];

        // ALORS douze étapes sont listées puis une ellipse
        self::assertStringContainsString('12. Cliquer sur « Bouton 12 »', $user);
        self::assertStringNotContainsString('13. Cliquer sur « Bouton 13 »', $user);
        self::assertStringContainsString('   …', $user);
    }
}
