<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use Mika\TestGeneratorBundle\Functional\Runtime\ScenarioExecutor;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeBrowser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScenarioExecutor::class)]
final class ScenarioExecutorTest extends TestCase
{
    private const PANEL = '#offcanvasNewPositionwaiting';

    private const CREATION = <<<'MD'
# Créer une position en attente

## Contexte
- Authentification : mot de passe, profil « trader »
- Page de départ : /

## Étapes
1. Cliquer sur le bouton « Nouvelle position en attente »
2. Saisir « 7200.00 » dans « Prix Achat CAC »
3. Saisir « 25 » dans « Quantité »
4. Sélectionner « Achat » dans la liste « Sens »
5. Cocher la case « Rendre le point d'entrée Actif »
6. Cliquer sur le bouton « Enregistrer la position »

## Attentes
- Le panneau « Nouvelle Position en attente » est fermé
- La table position contient 1 ligne de plus
MD;

    private ScenarioExecutor $executor;
    private FunctionalScenarioParser $parser;

    protected function setUp(): void
    {
        $this->executor = new ScenarioExecutor(expectationTimeoutMs: 0);
        $this->parser = new FunctionalScenarioParser();
    }

    #[Test]
    public function testRunsTheCreationScenarioEndToEnd(): void
    {
        // ÉTANT DONNÉ le scénario de création et une application qui ouvre le panneau puis l'enregistre
        $browser = FakeBrowser::realisticApp();

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse(self::CREATION), $browser);

        // ALORS la sonde réussit et décrit chaque étape
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertCount(6, $result->steps);
        self::assertSame(self::PANEL, $result->stepFor(1)?->opensContainer);
        self::assertNull($result->stepFor(1)?->closesContainer);
        self::assertSame('button[data-bs-target="#offcanvasNewPositionwaiting"]', $result->stepFor(1)?->target?->selector);
        self::assertSame(self::PANEL, $result->stepFor(2)?->target?->container);
        self::assertSame('#position_buyPrice', $result->stepFor(2)?->target?->relative);
        self::assertSame('buy', $result->stepFor(4)?->optionValue);
        self::assertSame('#isActive', $result->stepFor(5)?->target?->relative);
        self::assertSame(self::PANEL, $result->stepFor(6)?->closesContainer);
        self::assertNull($result->stepFor(6)?->opensContainer);
        self::assertSame(self::PANEL, $result->expectationFor(1)?->panelSelector);
        self::assertNull($result->expectationFor(2)?->panelSelector);
        self::assertSame([], $result->warnings);
        self::assertSame(
            [
                'visit /',
                'click button[data-bs-target="#offcanvasNewPositionwaiting"]',
                'fill #position_buyPrice = 7200.00',
                'fill #position_quantity = 25',
                'select #position_side = buy',
                'check #isActive',
                'click #offcanvasNewPositionwaiting button[type="submit"]',
            ],
            $browser->log,
        );
    }

    #[Test]
    public function testWaitsForAPanelThatClosesAfterTheClick(): void
    {
        // ÉTANT DONNÉ une application qui ne ferme le panneau et n'ajoute la ligne que quelques relevés après le clic
        $browser = FakeBrowser::realisticApp();
        $browser->lagScans = 4;

        // QUAND on exécute le scénario avec un délai d'attente suffisant
        $result = (new ScenarioExecutor(expectationTimeoutMs: 2000, pollMs: 1))->execute($this->parser->parse(self::CREATION), $browser);

        // ALORS les attentes sont constatées une fois la page à jour
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertSame(self::PANEL, $result->expectationFor(1)?->panelSelector);
    }

    #[Test]
    public function testReportsTheWaitWhenAnExpectationNeverComesTrue(): void
    {
        // ÉTANT DONNÉ une application dont le panneau ne se ferme jamais
        $browser = FakeBrowser::realisticApp();
        $browser->lagScans = 1000000;

        // QUAND on exécute le scénario avec un délai court
        $result = (new ScenarioExecutor(expectationTimeoutMs: 30, pollMs: 1))->execute($this->parser->parse(self::CREATION), $browser);

        // ALORS l'échec dit que le panneau est resté ouvert et combien de temps on a attendu
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('le panneau est ouvert, après 0.0 s d\'attente', (string) $result->error);
    }

    #[Test]
    public function testStopsOnAnAmbiguousStepAndAsksForADecision(): void
    {
        // ÉTANT DONNÉ une étape qui désigne deux boutons identiques
        $scenario = $this->parser->parse($this->scenario("Cliquer sur « Modifier l'objectif Core »", "L'URL est /"));

        // QUAND on exécute le scénario sans décision
        $result = $this->executor->execute($scenario, FakeBrowser::realisticApp());

        // ALORS la sonde s'arrête sur cette étape avec les candidats
        self::assertSame(ProbeStatus::NeedsDecision, $result->status);
        self::assertSame(1, $result->pending?->stepIndex);
        self::assertSame(ResolutionStatus::Ambiguous, $result->pending?->status);
        self::assertCount(2, $result->pending->candidates);
        self::assertSame('/', $result->pending->path);
        self::assertSame([], $result->steps);
    }

    #[Test]
    public function testAppliesADecisionAndGoesOn(): void
    {
        // ÉTANT DONNÉ l'étape ambiguë précédente et le premier candidat choisi
        $scenario = $this->parser->parse($this->scenario("Cliquer sur « Modifier l'objectif Core »", "L'URL est /"));
        $pending = $this->executor->execute($scenario, FakeBrowser::realisticApp())->pending;
        self::assertNotNull($pending);
        $decisions = (new ScenarioDecisions())->with(1, $pending->candidates[0]);
        $browser = FakeBrowser::realisticApp();

        // QUAND on rejoue avec la décision
        $result = $this->executor->execute($scenario, $browser, $decisions);

        // ALORS la sonde clique l'élément choisi et termine
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertSame('click ' . $pending->candidates[0]->selector, $browser->log[1]);
    }

    #[Test]
    public function testFailsWhenTheRowCountDoesNotMatch(): void
    {
        // ÉTANT DONNÉ un scénario qui attend deux lignes de plus alors que l'application n'en ajoute qu'une
        $markdown = str_replace('contient 1 ligne de plus', 'contient 2 lignes de plus', self::CREATION);

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), FakeBrowser::realisticApp());

        // ALORS la sonde échoue en nommant l'attente et le décompte observé
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('Attente 2', (string) $result->error);
        self::assertStringContainsString('passée de 10 à 11', (string) $result->error);
    }

    #[Test]
    public function testFailsWhenAPanelIsNotInTheExpectedState(): void
    {
        // ÉTANT DONNÉ un scénario qui s'arrête panneau ouvert alors qu'il attend sa fermeture
        $markdown = $this->scenario('Cliquer sur le bouton « Nouvelle position en attente »', 'Le panneau « Nouvelle Position en attente » est fermé');

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), FakeBrowser::realisticApp());

        // ALORS la sonde échoue
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('le panneau est ouvert', (string) $result->error);
    }

    #[Test]
    public function testFailsWhenThePanelTitleIsUnknown(): void
    {
        // ÉTANT DONNÉ une attente sur un panneau qui n'existe pas
        $markdown = $this->scenario('Cliquer sur le bouton « Nouvelle position en attente »', 'Le panneau « Fantôme » est ouvert');

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), FakeBrowser::realisticApp());

        // ALORS le message liste les panneaux connus
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('panneaux connus', (string) $result->error);
        self::assertStringContainsString('Nouvelle Position en attente', (string) $result->error);
    }

    #[Test]
    public function testFailsOnAnUnknownOptionAndListsTheAvailableOnes(): void
    {
        // ÉTANT DONNÉ une sélection d'une option absente de la liste
        $markdown = str_replace('« Achat » dans', '« Échange » dans', self::CREATION);

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), FakeBrowser::realisticApp());

        // ALORS l'échec cite l'étape et les options disponibles
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('Étape 4', (string) $result->error);
        self::assertStringContainsString('« Achat » (buy)', (string) $result->error);
        self::assertCount(3, $result->steps);
    }

    #[Test]
    public function testReportsABrowserErrorWithTheFailingStep(): void
    {
        // ÉTANT DONNÉ un navigateur qui ne trouve pas le champ de la deuxième étape
        $browser = $this->browserMissing('#position_buyPrice');

        // QUAND on exécute le scénario
        $result = $this->executor->execute($this->parser->parse(self::CREATION), $browser);

        // ALORS l'échec cite l'étape, son texte et l'erreur du navigateur
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('Étape 2', (string) $result->error);
        self::assertStringContainsString('Prix Achat CAC', (string) $result->error);
        self::assertStringContainsString('Élément introuvable', (string) $result->error);
        self::assertCount(1, $result->steps);
    }

    #[Test]
    public function testAnUnconfirmedTextBecomesAWarningNotAFailure(): void
    {
        // ÉTANT DONNÉ une attente de texte absente de la page observée
        $markdown = $this->scenario('Cliquer sur le bouton « Nouvelle position en attente »', 'Le message « Position créée » est affiché');

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), FakeBrowser::realisticApp());

        // ALORS la sonde réussit avec un avertissement : le test vérifiera le texte
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('Position créée', $result->warnings[0]);
    }

    #[Test]
    public function testUnmetUrlExpectationFails(): void
    {
        // ÉTANT DONNÉ une attente d'URL qui n'est pas celle de la page
        $markdown = $this->scenario('Cliquer sur le bouton « Nouvelle position en attente »', "L'URL est /portfolio");

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), FakeBrowser::realisticApp());

        // ALORS la sonde échoue en indiquant l'URL observée
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString("l'URL actuelle est « / »", (string) $result->error);
    }

    #[Test]
    public function testPanelRemovedFromThePageIsStillResolvedFromEarlierScans(): void
    {
        // ÉTANT DONNÉ une application qui retire le panneau du DOM après l'enregistrement
        $bare = new ScanResult('/', null, [], [], '');
        $browser = new FakeBrowser(
            ['dashboard' => Support\DomScannerFixture::dashboard(), 'panel' => Support\DomScannerFixture::panel(), 'bare' => $bare],
            [
                'button[data-bs-target="#offcanvasNewPositionwaiting"]' => ['to' => 'panel'],
                '#offcanvasNewPositionwaiting button[type="submit"]' => ['to' => 'bare', 'addRows' => 1],
            ],
            'dashboard',
        );

        // QUAND on exécute le scénario
        $result = $this->executor->execute($this->parser->parse(self::CREATION), $browser);

        // ALORS le panneau fermé est retrouvé grâce aux instantanés précédents
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertSame(self::PANEL, $result->expectationFor(1)?->panelSelector);
    }

    #[Test]
    public function testVisitStepNavigatesAndIsRecorded(): void
    {
        // ÉTANT DONNÉ un scénario qui passe par une navigation explicite
        $markdown = "# T\n## Étapes\n1. Aller sur /portfolio\n2. Cliquer sur le bouton « Nouvelle position en attente »\n## Attentes\n- Le panneau « Nouvelle Position en attente » est ouvert\n";
        $browser = FakeBrowser::realisticApp();

        // QUAND on l'exécute
        $result = $this->executor->execute($this->parser->parse($markdown), $browser);

        // ALORS la navigation est tracée avec son chemin
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertSame('/portfolio', $result->stepFor(1)?->pathAfter);
        self::assertNull($result->stepFor(1)?->target);
        self::assertSame(['visit /', 'visit /portfolio'], array_slice($browser->log, 0, 2));
    }

    #[Test]
    public function testResultSurvivesTheJsonExchangeBetweenProcesses(): void
    {
        // ÉTANT DONNÉ le résultat d'une exécution réussie
        $result = $this->executor->execute($this->parser->parse(self::CREATION), FakeBrowser::realisticApp());

        // QUAND on le transmet en JSON
        $decoded = ProbeResult::fromArray(json_decode($result->toJson(), true, 512, JSON_THROW_ON_ERROR));

        // ALORS il est identique
        self::assertSame($result->toJson(), $decoded->toJson());
    }

    private function scenario(string $step, string $expectation): string
    {
        return "# T\n## Étapes\n1. {$step}\n## Attentes\n- {$expectation}\n";
    }

    private function browserMissing(string $selectorPart): FakeBrowser
    {
        return new FakeBrowser(
            ['dashboard' => Support\DomScannerFixture::dashboard(), 'panel' => Support\DomScannerFixture::panel()],
            ['button[data-bs-target="#offcanvasNewPositionwaiting"]' => ['to' => 'panel']],
            'dashboard',
            [$selectorPart],
        );
    }
}
