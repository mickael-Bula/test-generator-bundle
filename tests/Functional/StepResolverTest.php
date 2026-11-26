<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Probe\DomScanner;
use Mika\TestGeneratorBundle\Functional\Probe\StepResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Les deux instantanés (tableau de bord et panneau « Nouvelle Position en attente » ouvert)
 * proviennent d'un vrai balayage Chromium, voir Fixture/.
 */
#[CoversClass(StepResolver::class)]
final class StepResolverTest extends TestCase
{
    private StepResolver $resolver;
    private ScanResult $dashboard;
    private ScanResult $panel;

    protected function setUp(): void
    {
        $this->resolver = new StepResolver();
        $this->dashboard = DomScanner::parse((string) file_get_contents(__DIR__ . '/Fixture/scan-dashboard.json'));
        $this->panel = DomScanner::parse((string) file_get_contents(__DIR__ . '/Fixture/scan-new-position-open.json'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fieldProvider(): iterable
    {
        yield 'prix achat CAC' => ['Prix Achat CAC', '#position_buyPrice'];
        yield 'prix achat LVC' => ['Prix Achat LVC (€)', '#position_lvcBuyPrice'];
        yield 'objectif CAC' => ['Objectif CAC', '#position_targetPrice'];
        yield 'objectif LVC' => ['Objectif LVC (€)', '#position_lvcTargetPrice'];
        yield 'quantité' => ['Quantité', '#position_quantity'];
        yield 'date de l\'opération' => ["Date de l'opération", '#position_createdAt'];
        yield 'date de validité' => ['Date de validité', '#position_expiresAt'];
        yield 'sans accent ni majuscule' => ['quantite', '#position_quantity'];
        yield 'placeholder' => ['Ex: 100', '#position_quantity'];
        yield 'name technique' => ['position[buyPrice]', '#position_buyPrice'];
        yield 'id technique' => ['position_targetPrice', '#position_targetPrice'];
    }

    #[Test]
    #[DataProvider('fieldProvider')]
    public function testResolvesEachFieldOfThePanel(string $label, string $expectedSelector): void
    {
        // ÉTANT DONNÉ le panneau ouvert et une étape de saisie
        $step = $this->step(StepAction::Fill, $label, '1');

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->panel);

        // ALORS le bon champ est retenu, avec son conteneur
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->element);
        self::assertSame($expectedSelector, $resolution->element->relative);
        self::assertSame('#offcanvasNewPositionwaiting', $resolution->element->container);
    }

    #[Test]
    public function testPartialLabelMatchingSeveralFieldsIsAmbiguous(): void
    {
        // ÉTANT DONNÉ un libellé qui figure dans deux champs
        $step = $this->step(StepAction::Fill, 'Prix Achat', '1');

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->panel);

        // ALORS elle est ambiguë et les deux champs sont proposés
        self::assertSame(ResolutionStatus::Ambiguous, $resolution->status);
        self::assertNull($resolution->element);
        self::assertCount(2, $resolution->candidates);
        $selectors = array_map(static fn ($c): string => $c->element->relative, $resolution->candidates);
        self::assertContains('#position_buyPrice', $selectors);
        self::assertContains('#position_lvcBuyPrice', $selectors);
    }

    #[Test]
    public function testExactMatchBeatsPartialMatch(): void
    {
        // ÉTANT DONNÉ « Objectif CAC », exact pour un champ et absent de l'autre
        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($this->step(StepAction::Fill, 'Objectif CAC', '1'), $this->panel);

        // ALORS le champ exact l'emporte sans ambiguïté
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('#position_targetPrice', $resolution->element?->relative);
    }

    #[Test]
    public function testTypoIsNeverResolvedSilently(): void
    {
        // ÉTANT DONNÉ un libellé avec une faute de frappe
        $step = $this->step(StepAction::Fill, 'Quantitee', '1');

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->panel);

        // ALORS rien n'est résolu, mais le champ le plus proche est proposé en premier
        self::assertSame(ResolutionStatus::NotFound, $resolution->status);
        self::assertNull($resolution->element);
        self::assertNotEmpty($resolution->candidates);
        self::assertSame('#position_quantity', $resolution->candidates[0]->element->relative);
        self::assertStringStartsWith('approchant', $resolution->candidates[0]->reason);
    }

    #[Test]
    public function testFillNeverTargetsAButton(): void
    {
        // ÉTANT DONNÉ une saisie dont le libellé est celui d'un bouton
        $step = $this->step(StepAction::Fill, 'Enregistrer la position', '1');

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->panel);

        // ALORS le bouton n'est pas retenu
        self::assertSame(ResolutionStatus::NotFound, $resolution->status);
        foreach ($resolution->candidates as $candidate) {
            self::assertSame('textbox', $candidate->element->kind());
        }
    }

    #[Test]
    public function testClickResolvesTheSubmitButtonInsideThePanel(): void
    {
        // ÉTANT DONNÉ l'étape « Cliquer sur le bouton Enregistrer la position »
        $step = $this->step(StepAction::Click, 'Enregistrer la position', null, 'bouton');

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->panel);

        // ALORS le bouton de soumission du panneau est retenu, avec un sélecteur relatif au panneau
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('button[type="submit"]', $resolution->element?->relative);
        self::assertSame('#offcanvasNewPositionwaiting button[type="submit"]', $resolution->element?->selector);
    }

    #[Test]
    public function testClickCanUseAnAriaLabel(): void
    {
        // ÉTANT DONNÉ un bouton sans texte mais avec un aria-label
        $resolution = $this->resolver->resolve($this->step(StepAction::Click, 'Close', null, 'bouton'), $this->panel);

        // ALORS il est retenu par son aria-label
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('button[aria-label="Close"]', $resolution->element?->relative);
        self::assertStringContainsString('aria-label', $resolution->candidates[0]->reason);
    }

    #[Test]
    public function testIconButtonIsFoundByTheTitleOfThePanelItOpens(): void
    {
        // ÉTANT DONNÉ un bouton-icône qui ouvre le panneau « Nouvelle Position en attente »
        $step = $this->step(StepAction::Click, 'Nouvelle position en attente', null, 'bouton');

        // QUAND on résout l'étape sur le tableau de bord
        $resolution = $this->resolver->resolve($step, $this->dashboard);

        // ALORS le bouton est retenu par la cible de son data-bs-target
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('button[data-bs-target="#offcanvasNewPositionwaiting"]', $resolution->element?->selector);
        self::assertFalse($resolution->element->fragile);
        self::assertStringContainsString('panneau', $resolution->candidates[0]->reason);
    }

    #[Test]
    public function testTwoButtonsOpeningTheSamePanelAreAmbiguous(): void
    {
        // ÉTANT DONNÉ deux boutons qui ouvrent le même panneau
        $step = $this->step(StepAction::Click, "Modifier l'objectif Core");

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->dashboard);

        // ALORS l'étape est ambiguë : le choix revient au LLM ou à l'auteur
        self::assertSame(ResolutionStatus::Ambiguous, $resolution->status);
        self::assertCount(2, $resolution->candidates);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function linkProvider(): iterable
    {
        yield 'lien dashboard' => ['Dashboard', 'lien', 'a[href="/"]'];
        yield 'lien portefeuille' => ['Portefeuille', 'lien', 'a[href="/portfolio"]'];
        yield 'lien paramètres' => ['Paramètres', 'lien', 'a[href="/settings"]'];
        yield 'bouton nommé sans le préciser' => ['Quotidien', '', 'body > main > div:nth-of-type(1) > button:nth-of-type(2)'];
    }

    #[Test]
    #[DataProvider('linkProvider')]
    public function testResolvesLinksAndTextButtonsIgnoringIconGlyphs(string $label, string $noun, string $selector): void
    {
        // ÉTANT DONNÉ des liens préfixés par un glyphe d'icône
        $step = $this->step(StepAction::Click, $label, null, '' === $noun ? null : $noun);

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->dashboard);

        // ALORS le glyphe est ignoré pour la comparaison
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame($selector, $resolution->element?->selector);
    }

    #[Test]
    public function testUnlabelledIconFallsBackToCompatibleElements(): void
    {
        // ÉTANT DONNÉ un libellé qui ne correspond à rien et des icônes sans nom
        $step = $this->step(StepAction::Click, 'Ajouter une position', null, 'icone');

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->dashboard);

        // ALORS elle échoue mais des éléments cliquables sont proposés pour le choix
        self::assertSame(ResolutionStatus::NotFound, $resolution->status);
        self::assertNotEmpty($resolution->candidates);
    }

    #[Test]
    public function testCheckboxIsFoundByTheTextThatFollowsIt(): void
    {
        // ÉTANT DONNÉ une case dont le libellé est placé après elle
        $step = $this->step(StepAction::Check, "Rendre le point d'entrée Actif");

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($step, $this->panel);

        // ALORS la case est retenue, et non le texte d'aide qui la précède
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('#isActive', $resolution->element?->relative);
        self::assertStringContainsString('suit', $resolution->candidates[0]->reason);
    }

    #[Test]
    public function testCheckboxPartialLabelIsEnoughWhenUnique(): void
    {
        // ÉTANT DONNÉ un libellé partiel mais unique
        $resolution = $this->resolver->resolve($this->step(StepAction::Uncheck, 'Actif'), $this->panel);

        // ALORS la case est retenue
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('#isActive', $resolution->element?->relative);
    }

    #[Test]
    public function testSelectResolvesTheListAndItsOptions(): void
    {
        // ÉTANT DONNÉ une liste « Sens » avec les options Achat et Vente
        $resolution = $this->resolver->resolve($this->step(StepAction::Select, 'Sens', 'Achat'), $this->panel);

        // QUAND on résout la liste
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        $select = $resolution->element;
        self::assertNotNull($select);

        // ALORS les options se résolvent par texte, valeur ou texte partiel unique
        self::assertSame('buy', $this->resolver->resolveOption($select, 'Achat'));
        self::assertSame('sell', $this->resolver->resolveOption($select, 'vente'));
        self::assertSame('buy', $this->resolver->resolveOption($select, 'buy'));
        self::assertSame('buy', $this->resolver->resolveOption($select, 'ach'));
        self::assertNull($this->resolver->resolveOption($select, 'Inconnu'));
    }

    #[Test]
    public function testDisabledElementIsReportedInsteadOfBeingClicked(): void
    {
        // ÉTANT DONNÉ un bouton correspondant mais désactivé
        $scan = $this->scanWith([
            ['tag' => 'button', 'type' => 'submit', 'text' => 'Enregistrer', 'disabled' => true, 'selector' => 'button[type="submit"]'],
        ]);

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($this->step(StepAction::Click, 'Enregistrer'), $scan);

        // ALORS elle échoue en signalant l'élément désactivé
        self::assertSame(ResolutionStatus::NotFound, $resolution->status);
        self::assertStringStartsWith('désactivé', $resolution->candidates[0]->reason);
    }

    #[Test]
    public function testEnabledElementWinsOverDisabledTwin(): void
    {
        // ÉTANT DONNÉ deux boutons au même texte dont un désactivé
        $scan = $this->scanWith([
            ['tag' => 'button', 'text' => 'Valider', 'disabled' => true, 'selector' => '#old'],
            ['tag' => 'button', 'text' => 'Valider', 'selector' => '#new'],
        ]);

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($this->step(StepAction::Click, 'Valider'), $scan);

        // ALORS le bouton actif est retenu
        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame('#new', $resolution->element?->selector);
    }

    #[Test]
    public function testIdenticalLabelsAreAmbiguous(): void
    {
        // ÉTANT DONNÉ deux boutons actifs au même texte
        $scan = $this->scanWith([
            ['tag' => 'button', 'text' => 'Supprimer', 'selector' => '#a'],
            ['tag' => 'button', 'text' => 'Supprimer', 'selector' => '#b'],
        ]);

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($this->step(StepAction::Click, 'Supprimer'), $scan);

        // ALORS l'ambiguïté est signalée avec les deux candidats dans l'ordre de la page
        self::assertSame(ResolutionStatus::Ambiguous, $resolution->status);
        self::assertSame('#a', $resolution->candidates[0]->element->selector);
        self::assertSame('#b', $resolution->candidates[1]->element->selector);
    }

    #[Test]
    public function testNounMismatchLowersAPartialMatchBelowTheThreshold(): void
    {
        // ÉTANT DONNÉ un bouton dont le texte contient seulement le libellé
        $scan = $this->scanWith([
            ['tag' => 'button', 'text' => 'Valider la commande', 'selector' => '#order'],
        ]);

        // QUAND l'auteur demande un « lien » alors que l'élément est un bouton
        $asLink = $this->resolver->resolve($this->step(StepAction::Click, 'Valider', null, 'lien'), $scan);
        $asButton = $this->resolver->resolve($this->step(StepAction::Click, 'Valider', null, 'bouton'), $scan);

        // ALORS la correspondance partielle est écartée pour « lien » et retenue pour « bouton »
        self::assertSame(ResolutionStatus::NotFound, $asLink->status);
        self::assertSame(ResolutionStatus::Resolved, $asButton->status);
        self::assertSame('#order', $asButton->element?->selector);
    }

    #[Test]
    public function testVeryShortLabelsDoNotMatchByContainment(): void
    {
        // ÉTANT DONNÉ un libellé de deux lettres contenu dans beaucoup de textes
        $scan = $this->scanWith([
            ['tag' => 'button', 'text' => 'Mon profil', 'selector' => '#a'],
        ]);

        // QUAND on résout l'étape
        $resolution = $this->resolver->resolve($this->step(StepAction::Click, 'on'), $scan);

        // ALORS aucune correspondance partielle n'est retenue
        self::assertSame(ResolutionStatus::NotFound, $resolution->status);
    }

    private function step(StepAction $action, string $target, ?string $value = null, ?string $noun = null): Step
    {
        return new Step(1, $action, $target, $value, $noun, $target, 1);
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    private function scanWith(array $elements): ScanResult
    {
        return new ScanResult('/', null, [], array_map(static fn (array $e): ScannedElement => ScannedElement::fromArray($e), $elements), '');
    }
}
