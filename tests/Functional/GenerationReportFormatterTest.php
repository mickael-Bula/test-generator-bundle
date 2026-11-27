<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\GenerationReport;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Dto\StepReport;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionMethod;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Generation\GenerationReportFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenerationReportFormatter::class)]
final class GenerationReportFormatterTest extends TestCase
{
    #[Test]
    public function testListsEachStepWithItsResolutionMethod(): void
    {
        // ÉTANT DONNÉ un rapport de réussite avec une étape par règle, une par LLM (fragile) et une navigation
        $report = GenerationReport::generated('Titre', 'TitreTest', '<?php', [
            new StepReport(1, 'Cliquer sur le bouton « A »', ResolutionMethod::Rule, '#a', '#panel', false),
            new StepReport(2, 'Cliquer sur « B »', ResolutionMethod::Llm, 'body > button', null, true),
            new StepReport(3, 'Aller sur /x', ResolutionMethod::Navigation, null, null, false),
        ], ['Avertissement un'], 2);

        // QUAND on le formate
        $lines = (new GenerationReportFormatter())->lines($report);

        // ALORS chaque étape indique sa méthode, sa fragilité et sa cible
        self::assertSame([
            'Scénario : Titre',
            'Étapes résolues :',
            '  1. Cliquer sur le bouton « A »  [règle]  #panel #a',
            '  2. Cliquer sur « B »  [LLM] (fragile)  body > button',
            '  3. Aller sur /x  [navigation]',
            'Avertissement : Avertissement un',
            'Exécutions de la sonde : 2',
        ], $lines);
    }

    #[Test]
    public function testDoesNotRepeatThePanelWhenTheSelectorAlreadyStartsWithIt(): void
    {
        // ÉTANT DONNÉ un sélecteur déjà préfixé par son panneau (bouton « submit » non unique dans la page)
        $report = GenerationReport::generated('T', 'TTest', '<?php', [
            new StepReport(1, 'Cliquer sur « Enregistrer »', ResolutionMethod::Rule, '#panel button[type="submit"]', '#panel', false),
        ], [], 1);

        // QUAND on formate le rapport
        $lines = (new GenerationReportFormatter())->lines($report);

        // ALORS le panneau n'apparaît qu'une fois
        self::assertContains('  1. Cliquer sur « Enregistrer »  [règle]  #panel button[type="submit"]', $lines);
    }

    #[Test]
    public function testListsTheCandidatesWhenADecisionIsNeeded(): void
    {
        // ÉTANT DONNÉ une génération arrêtée sur une étape ambiguë
        $step = new Step(1, StepAction::Click, 'Modifier', null, null, 'Cliquer sur « Modifier »', 8);
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [
            new ScannedElement(tag: 'button', targetTitle: 'Objectif Core', selector: '#a'),
            new ScannedElement(tag: 'button', targetTitle: 'Objectif Satellite', selector: '#b'),
        ]);

        // QUAND on formate le rapport
        $lines = (new GenerationReportFormatter())->lines(GenerationReport::needsDecision('T', 'TTest', [], $step, $pending, 1));

        // ALORS les candidats sont numérotés et la suite à donner est indiquée
        self::assertContains('Décision requise à l\'étape 1 : Cliquer sur « Modifier » (plusieurs éléments correspondent)', $lines);
        self::assertContains('  1. <button> · titre du panneau ouvert « Objectif Core » · sélecteur #a', $lines);
        self::assertContains('  2. <button> · titre du panneau ouvert « Objectif Satellite » · sélecteur #b', $lines);
        self::assertContains('Précisez le libellé de l\'étape dans le scénario, ou relancez sans --no-llm pour que le LLM tranche.', $lines);
    }

    #[Test]
    public function testShowsThePageAndTheCandidatesWhenTheChoiceFails(): void
    {
        // ÉTANT DONNÉ un échec survenu en cherchant à trancher une étape, sur la page /login
        $step = new Step(1, StepAction::Click, 'Nouvelle position', null, null, 'Cliquer sur « Nouvelle position »', 8);
        $pending = new PendingDecision(1, ResolutionStatus::NotFound, [new ScannedElement(tag: 'button', text: 'Se connecter', selector: 'button[type="submit"]')], '/login');
        $report = GenerationReport::failed('T', 'TTest', [], 'Étape 1 : choix impossible : délai dépassé', 1, [], $step, $pending);

        // QUAND on formate le rapport
        $lines = (new GenerationReportFormatter())->lines($report);

        // ALORS la page courante et les candidats sont visibles, sans l'invite réservée au cas « décision requise »
        self::assertContains('Étape non résolue par les règles à l\'étape 1 : Cliquer sur « Nouvelle position » (aucun élément ne correspond)', $lines);
        self::assertContains('Page courante : /login', $lines);
        self::assertContains('  1. <button> · texte « Se connecter » · sélecteur button[type="submit"]', $lines);
        self::assertContains('Échec : Étape 1 : choix impossible : délai dépassé', $lines);
        self::assertFalse(in_array('Précisez le libellé de l\'étape dans le scénario, ou relancez sans --no-llm pour que le LLM tranche.', $lines, true));
    }

    #[Test]
    public function testWordsANotFoundDecisionDifferently(): void
    {
        // ÉTANT DONNÉ une étape dont aucun élément ne correspond
        $step = new Step(2, StepAction::Click, 'Zzz', null, null, 'Cliquer sur « Zzz »', 9);
        $pending = new PendingDecision(2, ResolutionStatus::NotFound, [new ScannedElement(tag: 'a', text: 'Accueil', selector: '#home')]);

        // QUAND on formate le rapport
        $lines = (new GenerationReportFormatter())->lines(GenerationReport::needsDecision('T', 'TTest', [], $step, $pending, 1));

        // ALORS la raison est « aucun élément ne correspond »
        self::assertContains('Décision requise à l\'étape 2 : Cliquer sur « Zzz » (aucun élément ne correspond)', $lines);
    }

    #[Test]
    public function testShowsTheErrorOfAFailedGeneration(): void
    {
        // ÉTANT DONNÉ un échec avant même la lecture du scénario (titre vide, aucune exécution)
        // QUAND on formate le rapport
        $lines = (new GenerationReportFormatter())->lines(GenerationReport::failed('', '', [], 'Ligne 3 : étape non reconnue', 0));

        // ALORS seul l'échec est affiché
        self::assertSame(['Échec : Ligne 3 : étape non reconnue'], $lines);
    }
}
