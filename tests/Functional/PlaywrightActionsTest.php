<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Runtime\PlaywrightActions;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeLocator;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakePage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaywrightActions::class)]
final class PlaywrightActionsTest extends TestCase
{
    private PlaywrightActions $actions;

    protected function setUp(): void
    {
        $this->actions = new PlaywrightActions(timeoutMs: 40, pollMs: 1);
    }

    #[Test]
    public function testWaitUntilVisibleReturnsOnceTheElementAppears(): void
    {
        // ÉTANT DONNÉ un élément invisible aux deux premières lectures
        $locator = new FakeLocator('#panel', [false, false, true]);

        // QUAND on attend sa visibilité
        $this->actions->waitUntilVisible($locator);

        // ALORS l'attente se termine sans erreur
        self::assertTrue($locator->isVisible());
    }

    #[Test]
    public function testWaitUntilVisibleTimesOutWithAClearMessage(): void
    {
        // ÉTANT DONNÉ un élément qui ne s'affiche jamais
        $locator = new FakeLocator('#panel', [false]);

        // QUAND on attend sa visibilité
        // ALORS le délai dépassé est signalé
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Délai de 40 ms dépassé : l\'élément ne s\'est pas affiché');

        $this->actions->waitUntilVisible($locator);
    }

    #[Test]
    public function testWaitUntilHiddenAcceptsAnElementRemovedFromThePage(): void
    {
        // ÉTANT DONNÉ un élément d'abord visible, puis retiré de la page (isVisible renvoie false)
        $locator = new FakeLocator('#panel', [true, true, false]);

        // QUAND on attend sa disparition
        $this->actions->waitUntilHidden($locator);

        // ALORS l'attente se termine
        self::assertFalse($locator->isVisible());
    }

    #[Test]
    public function testWaitUntilHiddenTimesOutWhenStillVisible(): void
    {
        // ÉTANT DONNÉ un élément toujours visible
        $locator = new FakeLocator('#panel', [true]);

        // QUAND on attend sa disparition
        // ALORS le message dit qu'il est resté affiché
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('l\'élément est resté affiché');

        $this->actions->waitUntilHidden($locator);
    }

    #[Test]
    public function testConditionErrorsDuringNavigationAreRetried(): void
    {
        // ÉTANT DONNÉ un élément dont la lecture échoue pendant une navigation, puis réussit
        $locator = new FakeLocator('#panel', [new \RuntimeException('Execution context was destroyed'), true]);

        // QUAND on attend sa visibilité
        $this->actions->waitUntilVisible($locator);

        // ALORS l'erreur transitoire est absorbée
        self::assertTrue($locator->isVisible());
    }

    /**
     * @return iterable<string, array{string, string, bool, string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'hôte ignoré' => ['http://localhost/positions', '/positions', false, '/positions'];
        yield 'barre finale ignorée' => ['http://localhost/positions/', '/positions', false, '/positions'];
        yield 'racine' => ['http://localhost', '/', false, '/'];
        yield 'requête ignorée par défaut' => ['http://localhost/positions?page=2', '/positions', false, '/positions'];
        yield 'requête conservée si demandée' => ['http://localhost/positions?page=2', '/positions?page=2', true, '/positions?page=2'];
        yield 'chemin relatif seul' => ['/settings', '/settings', false, '/settings'];
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function testComparableUrl(string $url, string $expected, bool $withQuery, string $comparable): void
    {
        // ÉTANT DONNÉE une URL du navigateur
        // QUAND on la rend comparable
        // ALORS seul le chemin (et la requête si demandée) subsiste
        self::assertSame($comparable, $this->actions->comparableUrl($url, $withQuery));
        self::assertSame($comparable, $this->actions->comparableUrl($expected, $withQuery));
    }

    #[Test]
    public function testWaitUntilUrlFollowsNavigation(): void
    {
        // ÉTANT DONNÉ une page qui change d'URL après deux lectures
        $page = new FakePage(['http://localhost/', 'http://localhost/', 'http://localhost/positions/']);

        // QUAND on attend l'URL « /positions »
        $this->actions->waitUntilUrl($page, '/positions');

        // ALORS l'attente se termine
        self::assertSame('http://localhost/positions/', $page->url());
    }

    #[Test]
    public function testWaitUntilUrlFailureShowsTheCurrentUrl(): void
    {
        // ÉTANT DONNÉ une page qui reste sur la racine
        $page = new FakePage(['http://localhost/']);

        // QUAND on attend « /positions »
        // ALORS le message cite l'URL attendue et l'URL actuelle
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« /positions » n\'a pas été atteinte (URL actuelle : http://localhost/)');

        $this->actions->waitUntilUrl($page, '/positions');
    }

    #[Test]
    public function testWaitUntilUrlContains(): void
    {
        // ÉTANT DONNÉ une page dont l'URL finit par contenir « settings »
        $page = new FakePage(['http://localhost/', 'http://localhost/settings/profile']);

        // QUAND on attend cette sous-chaîne
        $this->actions->waitUntilUrlContains($page, 'settings');

        // ALORS l'attente se termine
        self::assertStringContainsString('settings', $page->url());
    }

    #[Test]
    public function testTextWaitsIgnoreIconGlyphsAndWhitespace(): void
    {
        // ÉTANT DONNÉ une page dont le texte contient des glyphes d'icône et des retours à la ligne
        $body = new FakeLocator('body', [true], "\u{F0BDF} Dashboard\n\n  Position   créée ");
        $page = new FakePage(prepared: ['body' => $body]);

        // QUAND on attend un texte visible puis l'absence d'un autre
        $this->actions->waitUntilTextVisible($page, 'Position créée');
        $this->actions->waitUntilTextGone($page, 'Erreur');

        // ALORS le texte visible est nettoyé et les deux attentes aboutissent
        self::assertSame('Dashboard Position créée', $this->actions->visibleText($page));
    }

    #[Test]
    public function testTextGoneTimesOutWhenStillPresent(): void
    {
        // ÉTANT DONNÉ un texte qui reste affiché
        $page = new FakePage(prepared: ['body' => new FakeLocator('body', [true], 'Erreur : champ requis')]);

        // QUAND on attend sa disparition
        // ALORS l'attente échoue en nommant le texte
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('le texte « Erreur » est resté affiché');

        $this->actions->waitUntilTextGone($page, 'Erreur');
    }

    #[Test]
    public function testSelectValueUsesTheStringFormFirst(): void
    {
        // ÉTANT DONNÉ un locator dont selectOption accepte une chaîne
        $locator = new class() {
            public mixed $received = null;

            public function selectOption(string|array $values): void
            {
                $this->received = $values;
            }
        };

        // QUAND on sélectionne une valeur
        $this->actions->selectValue($locator, 'buy');

        // ALORS la valeur est transmise telle quelle
        self::assertSame('buy', $locator->received);
    }

    #[Test]
    public function testSelectValueFallsBackToAnArray(): void
    {
        // ÉTANT DONNÉ un locator dont selectOption n'accepte qu'un tableau
        $locator = new class() {
            public mixed $received = null;

            public function selectOption(array $values): void
            {
                $this->received = $values;
            }
        };

        // QUAND on sélectionne une valeur
        $this->actions->selectValue($locator, 'buy');

        // ALORS elle est transmise dans un tableau
        self::assertSame(['buy'], $locator->received);
    }

    #[Test]
    public function testSelectValueExplainsWhenTheMethodIsMissing(): void
    {
        // ÉTANT DONNÉ un locator sans selectOption
        // QUAND on sélectionne une valeur
        // ALORS l'erreur indique où adapter
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('PlaywrightActions::selectValue()');

        $this->actions->selectValue(new \stdClass(), 'buy');
    }

    #[Test]
    public function testSetCheckedUsesCheckAndUncheckWhenAvailable(): void
    {
        // ÉTANT DONNÉ un locator avec check() et uncheck()
        $locator = new class() {
            /** @var list<string> */
            public array $calls = [];

            public function check(): void
            {
                $this->calls[] = 'check';
            }

            public function uncheck(): void
            {
                $this->calls[] = 'uncheck';
            }
        };

        // QUAND on coche puis décoche
        $this->actions->setChecked($locator, true);
        $this->actions->setChecked($locator, false);

        // ALORS les méthodes natives sont appelées
        self::assertSame(['check', 'uncheck'], $locator->calls);
    }

    #[Test]
    public function testSetCheckedClicksOnlyWhenTheStateDiffers(): void
    {
        // ÉTANT DONNÉ un locator sans check() mais avec isChecked() et click()
        $locator = new class() {
            public int $clicks = 0;

            public function __construct(public bool $checked = false)
            {
            }

            public function isChecked(): bool
            {
                return $this->checked;
            }

            public function click(): void
            {
                ++$this->clicks;
                $this->checked = !$this->checked;
            }
        };

        // QUAND on demande l'état coché deux fois
        $this->actions->setChecked($locator, true);
        $this->actions->setChecked($locator, true);

        // ALORS un seul clic a lieu
        self::assertSame(1, $locator->clicks);
        self::assertTrue($locator->checked);
    }

    #[Test]
    public function testSetCheckedExplainsWhenNothingIsAvailable(): void
    {
        // ÉTANT DONNÉ un locator sans aucune méthode de case à cocher
        // QUAND on le coche
        // ALORS l'erreur indique où adapter
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('PlaywrightActions::setChecked()');

        $this->actions->setChecked(new \stdClass(), true);
    }

    #[Test]
    public function testSettleReturnsAfterTwoIdenticalReads(): void
    {
        // ÉTANT DONNÉ un DOM qui change deux fois avant de se stabiliser
        $page = new FakePage(evaluations: ['10:5', '12:6', '14:6', '14:6']);

        // QUAND on attend la stabilité
        $this->actions->settle($page, 500);

        // ALORS quatre relevés ont été nécessaires
        self::assertCount(4, array_filter($page->calls, static fn (string $c): bool => 'evaluate' === $c));
    }

    #[Test]
    public function testSettleToleratesNetworkIdleTimeoutAndNavigationErrors(): void
    {
        // ÉTANT DONNÉ une page où networkidle échoue et dont le premier relevé plante (navigation)
        $page = new FakePage(evaluations: [new \RuntimeException('navigation'), '8:3', '8:3'], networkIdleFails: true);

        // QUAND on attend la stabilité
        $this->actions->settle($page, 500);

        // ALORS aucune exception ne remonte
        self::assertContains('waitForLoadState networkidle', $page->calls);
    }

    #[Test]
    public function testSettleGivesUpAtTheDeadline(): void
    {
        // ÉTANT DONNÉ un DOM qui change à chaque relevé
        $changing = array_map(static fn (int $i): string => $i . ':1', range(1, 500));
        $page = new FakePage(evaluations: $changing);
        $started = microtime(true);

        // QUAND on attend la stabilité avec un délai court
        $this->actions->settle($page, 30);

        // ALORS l'attente s'arrête sans erreur à l'échéance
        self::assertLessThan(0.5, microtime(true) - $started);
    }
}
