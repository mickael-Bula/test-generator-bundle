<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ResolvedExpectation;
use Mika\TestGeneratorBundle\Functional\Dto\ResolvedStep;
use Mika\TestGeneratorBundle\Functional\Dto\Target;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use Mika\TestGeneratorBundle\Functional\Render\FunctionalTestRenderer;
use Mika\TestGeneratorBundle\Functional\Render\RenderOptions;
use Mika\TestGeneratorBundle\Functional\Runtime\ScenarioExecutor;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ContractE2EBase;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeBrowser;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeLocator;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakePage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionalTestRenderer::class)]
final class FunctionalTestRendererTest extends TestCase
{
    private const PANEL = '#offcanvasNewPositionwaiting';

    private FunctionalTestRenderer $renderer;
    private RenderOptions $options;

    protected function setUp(): void
    {
        $this->renderer = new FunctionalTestRenderer();
        $this->options = new RenderOptions('App\Tests\Functional', 'App\Tests\E2ETest');
    }

    #[Test]
    public function testRendersTheCreationScenarioExactly(): void
    {
        // ÉTANT DONNÉ le scénario de création exécuté par la sonde
        $scenario = $this->parse($this->creationMarkdown());
        $probe = (new ScenarioExecutor(expectationTimeoutMs: 0))->execute($scenario, FakeBrowser::realisticApp());

        // QUAND on génère le test
        $source = $this->renderer->render($scenario, $probe, $this->options);

        // ALORS le résultat est celui de référence
        self::assertSame((string) file_get_contents(__DIR__ . '/Fixture/creation-expected.php.txt'), $source);
    }

    #[Test]
    public function testGeneratedSourceIsValidPhp(): void
    {
        // ÉTANT DONNÉ un test généré
        $scenario = $this->parse($this->creationMarkdown());
        $probe = (new ScenarioExecutor(expectationTimeoutMs: 0))->execute($scenario, FakeBrowser::realisticApp());
        $file = tempnam(sys_get_temp_dir(), 'mika_render_') . '.php';
        file_put_contents($file, $this->renderer->render($scenario, $probe, $this->options));

        // QUAND on le vérifie avec le PHP en ligne de commande
        exec(PHP_BINARY . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        unlink($file);

        // ALORS la syntaxe est valide
        self::assertSame(0, $code, implode("\n", $output));
    }

    #[Test]
    public function testRefusesAProbeThatDidNotSucceed(): void
    {
        // ÉTANT DONNÉ une sonde qui attend une décision
        $scenario = $this->parse($this->creationMarkdown());
        $probe = ProbeResult::needsDecision([], new PendingDecision(1, ResolutionStatus::Ambiguous, []));

        // QUAND on génère
        // ALORS le gabarit refuse
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('doit être « ok »');

        $this->renderer->render($scenario, $probe, $this->options);
    }

    #[Test]
    public function testReportsAStepTheProbeDidNotResolve(): void
    {
        // ÉTANT DONNÉ une sonde « ok » mais sans l'étape 1
        $scenario = $this->parse($this->creationMarkdown());
        $probe = ProbeResult::ok([], []);

        // QUAND on génère
        // ALORS l'étape manquante est nommée
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('n\'a pas résolu l\'étape 1');

        $this->renderer->render($scenario, $probe, $this->options);
    }

    #[Test]
    public function testRendersNavigationUrlTextAndRowCountExpectations(): void
    {
        // ÉTANT DONNÉ un scénario de navigation avec attentes d'URL, de texte et de lignes
        $scenario = $this->parse(<<<'MD'
# Voir les positions de l'utilisateur

## Contexte
- Authentification : aucune

## Étapes
1. Aller sur /portfolio
2. Cliquer sur le lien « Positions »

## Attentes
- L'URL est /positions?page=2
- L'URL contient positions
- Le texte « L'ordre n'existe plus » est affiché
- Le message « Erreur » n'est plus affiché
- La table position contient 2 lignes de moins
- La table order contient 7 lignes
MD);
        $probe = ProbeResult::ok(
            [
                new ResolvedStep(1, null, null, null, null, '/portfolio'),
                new ResolvedStep(2, new Target('a[href="/positions"]', 'a[href="/positions"]', null, false), null, null, null, '/positions'),
            ],
            array_map(static fn (int $i): ResolvedExpectation => new ResolvedExpectation($i, null), [1, 2, 3, 4, 5, 6]),
        );

        // QUAND on génère
        $source = $this->renderer->render($scenario, $probe, $this->options);

        // ALORS chaque ligne attendue est produite, sans connexion puisque l'authentification est absente
        self::assertStringNotContainsString('loginAs', $source);
        foreach ([
            '$page = $this->open(\'/\');',
            '$page = $this->open(\'/portfolio\');',
            '$page->locator(\'a[href="/positions"]\')->click();',
            '$this->waitUntilUrl($page, \'/positions\');',
            '$this->waitUntilUrl($page, \'/positions?page=2\');',
            'self::assertSame(\'/positions?page=2\', $this->currentPath($page, \'/positions?page=2\'));',
            'self::assertStringContainsString(\'positions\', (string) $page->url());',
            '$this->waitUntilTextVisible($page, \'L\\\'ordre n\\\'existe plus\');',
            '$positionRowsBefore = $this->countRows(\'position\');',
            'self::assertSame($positionRowsBefore - 2, $this->countRows(\'position\'));',
            'self::assertSame(7, $this->countRows(\'order\'));',
        ] as $expected) {
            self::assertStringContainsString($expected, $source);
        }
    }

    #[Test]
    public function testNamesSeveralPanelsAndQuotesApostrophes(): void
    {
        // ÉTANT DONNÉ deux panneaux et une valeur contenant une apostrophe
        $scenario = $this->parse(<<<'MD'
# Deux panneaux

## Étapes
1. Saisir « l'ordre » dans le champ « Commentaire »
2. Cliquer sur le bouton « Fermer »

## Attentes
- Le panneau « Détails » est ouvert
MD);
        $field = new Target('#c', '#c', '#first', false);
        $probe = ProbeResult::ok(
            [
                new ResolvedStep(1, $field, null, null, null, null),
                new ResolvedStep(2, new Target('#close', '#close', '#second', false), null, null, null, null),
            ],
            [new ResolvedExpectation(1, '#second')],
        );

        // QUAND on génère
        $source = $this->renderer->render($scenario, $probe, $this->options);

        // ALORS les variables sont $panel puis $panel2 et l'apostrophe est échappée
        self::assertStringContainsString('$panel = $page->locator(\'#first\');', $source);
        self::assertStringContainsString('$panel2 = $page->locator(\'#second\');', $source);
        self::assertStringContainsString('$panel->locator(\'#c\')->fill(\'l\\\'ordre\');', $source);
        self::assertStringContainsString('self::assertTrue($panel2->isVisible());', $source);
    }

    #[Test]
    public function testRendersCheckAndUncheckWithOppositeStates(): void
    {
        // ÉTANT DONNÉ un scénario qui coche puis décoche des cases
        $scenario = $this->parse(<<<'MD'
# Préférences

## Étapes
1. Cocher la case « Alertes »
2. Décocher la case « Newsletter »

## Attentes
- L'URL est /preferences
MD);
        $probe = ProbeResult::ok(
            [
                new ResolvedStep(1, new Target('#alerts', '#alerts', null, false), null, null, null, null),
                new ResolvedStep(2, new Target('#news', '#news', null, false), null, null, null, null),
            ],
            [new ResolvedExpectation(1, null)],
        );

        // QUAND on génère
        $source = $this->renderer->render($scenario, $probe, $this->options);

        // ALORS la première case est cochée, la seconde décochée
        self::assertStringContainsString('$this->setChecked($page->locator(\'#alerts\'), true);', $source);
        self::assertStringContainsString('$this->setChecked($page->locator(\'#news\'), false);', $source);
    }

    #[Test]
    public function testDoesNotRepeatAWaitAlreadyIssuedByTheLastStep(): void
    {
        // ÉTANT DONNÉ le scénario de création (le panneau se ferme à la dernière étape)
        $scenario = $this->parse($this->creationMarkdown());
        $probe = (new ScenarioExecutor(expectationTimeoutMs: 0))->execute($scenario, FakeBrowser::realisticApp());

        // QUAND on génère
        $source = $this->renderer->render($scenario, $probe, $this->options);

        // ALORS l'attente de fermeture n'apparaît qu'une fois
        self::assertSame(1, substr_count($source, '$this->waitUntilHidden($panel);'));
    }

    #[Test]
    public function testGeneratedTestRunsAgainstTheRealHelpers(): void
    {
        // ÉTANT DONNÉ le test généré, une page factice dont le panneau s'ouvre puis se ferme
        // et les vraies aides du bundle comme classe de base
        $scenario = $this->parse($this->creationMarkdown());
        $probe = (new ScenarioExecutor(expectationTimeoutMs: 0))->execute($scenario, FakeBrowser::realisticApp());
        $options = new RenderOptions('Mika\TestGeneratorBundle\Tests\Functional\Generated', ContractE2EBase::class, 'ContractGeneratedTest');
        $source = $this->renderer->render($scenario, $probe, $options);

        $log = new \ArrayObject();
        $panel = new FakeLocator(self::PANEL, [true, false], log: $log);
        ContractE2EBase::$page = new FakePage(prepared: [self::PANEL => $panel]);
        ContractE2EBase::$page->log = $log;
        ContractE2EBase::$logins = [];
        ContractE2EBase::$visited = [];
        ContractE2EBase::$counts = ['position' => [3, 4]];

        $class = 'Mika\TestGeneratorBundle\Tests\Functional\Generated\ContractGeneratedTest';
        if (!class_exists($class, false)) {
            $file = tempnam(sys_get_temp_dir(), 'mika_contract_') . '.php';
            file_put_contents($file, $source);
            require $file;
            unlink($file);
        }

        // QUAND on exécute le test généré
        $generated = new $class('testCreerUnePositionEnAttente');
        $generated->testCreerUnePositionEnAttente();

        // ALORS les actions arrivent dans l'ordre sur les bons éléments, avec les bonnes valeurs
        self::assertSame(['trader'], ContractE2EBase::$logins);
        self::assertSame(['/'], ContractE2EBase::$visited);
        self::assertSame(
            [
                'button[data-bs-target="#offcanvasNewPositionwaiting"] :: click',
                self::PANEL . ' >> #position_buyPrice :: fill 7200.00',
                self::PANEL . ' >> #position_quantity :: fill 25',
                self::PANEL . ' >> #position_side :: select buy',
                self::PANEL . ' >> #isActive :: check',
                self::PANEL . ' >> button[type="submit"] :: click',
            ],
            ContractE2EBase::$page->log->getArrayCopy(),
        );
        self::assertSame([], ContractE2EBase::$counts['position']);
    }

    private function parse(string $markdown): FunctionalScenario
    {
        return (new FunctionalScenarioParser())->parse($markdown);
    }

    private function creationMarkdown(): string
    {
        return (string) (new \ReflectionClassConstant(ScenarioExecutorTest::class, 'CREATION'))->getValue();
    }
}
