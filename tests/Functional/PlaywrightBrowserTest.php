<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\Target;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use Mika\TestGeneratorBundle\Functional\Runtime\PlaywrightActions;
use Mika\TestGeneratorBundle\Functional\Runtime\PlaywrightBrowser;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakePage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaywrightBrowser::class)]
final class PlaywrightBrowserTest extends TestCase
{
    #[Test]
    public function testVisitKeepsThePageReturnedByTheHost(): void
    {
        // ÉTANT DONNÉ un navigateur dont la fermeture de visite renvoie une page
        $page = new FakePage();
        $visited = [];
        $browser = $this->browser($page, $visited);

        // QUAND on visite un chemin puis on scanne
        $browser->visit('/portfolio');
        $browser->settle();

        // ALORS la page de l'hôte est utilisée
        self::assertSame(['/portfolio'], $visited);
        self::assertContains('waitForLoadState networkidle', $page->calls);
    }

    #[Test]
    public function testScanConvertsTheEvaluationOfTheScript(): void
    {
        // ÉTANT DONNÉ une page dont evaluate() renvoie un vrai balayage décodé
        $raw = json_decode((string) file_get_contents(__DIR__ . '/Fixture/scan-new-position-open.json'), true, 512, JSON_THROW_ON_ERROR);
        $page = new FakePage(evaluations: [$raw]);
        $visited = [];
        $browser = $this->browser($page, $visited);
        $browser->visit('/');

        // QUAND on scanne
        $scan = $browser->scan();

        // ALORS le résultat est typé
        self::assertSame('#offcanvasNewPositionwaiting', $scan->container?->selector);
        self::assertCount(11, $scan->elements);
    }

    #[Test]
    public function testActionsUseTheContainerWhenThereIsOne(): void
    {
        // ÉTANT DONNÉ une cible dans un panneau et une cible hors panneau
        $page = new FakePage();
        $visited = [];
        $browser = $this->browser($page, $visited);
        $browser->visit('/');
        $inPanel = new Target('#position_buyPrice', '#position_buyPrice', '#offcanvasNewPositionwaiting', false);
        $outside = new Target('a[href="/"]', 'a[href="/"]', null, false);

        // QUAND on clique et on saisit
        $browser->fill($inPanel, '7200.00');
        $browser->click($outside);

        // ALORS le panneau est interrogé d'abord, puis l'élément relatif ; sinon le sélecteur complet
        self::assertSame(
            ['#offcanvasNewPositionwaiting >> #position_buyPrice :: fill 7200.00', 'a[href="/"] :: click'],
            $page->log->getArrayCopy(),
        );
    }

    #[Test]
    public function testSelectAndCheckDelegateToTheActions(): void
    {
        // ÉTANT DONNÉ un locator dont selectOption et check existent
        $locator = new class('#x') {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(public string $selector)
            {
            }

            public function selectOption(string $value): void
            {
                $this->calls[] = 'select ' . $value;
            }

            public function check(): void
            {
                $this->calls[] = 'check';
            }

            public function uncheck(): void
            {
                $this->calls[] = 'uncheck';
            }
        };
        $page = new FakePage(prepared: ['#x' => $locator]);
        $visited = [];
        $browser = $this->browser($page, $visited);
        $browser->visit('/');
        $target = new Target('#x', '#x', null, false);

        // QUAND on sélectionne, coche et décoche
        $browser->selectOption($target, 'buy');
        $browser->setChecked($target, true);
        $browser->setChecked($target, false);

        // ALORS les appels natifs sont faits
        self::assertSame(['select buy', 'check', 'uncheck'], $locator->calls);
    }

    #[Test]
    public function testCountRowsUsesTheHostConnection(): void
    {
        // ÉTANT DONNÉ un navigateur dont le compteur est fourni par l'hôte
        $visited = [];
        $browser = $this->browser(new FakePage(), $visited);

        // QUAND on compte les lignes d'une table
        // ALORS la valeur de l'hôte est renvoyée
        self::assertSame(42, $browser->countRows('position'));
    }

    #[Test]
    public function testUsingThePageBeforeVisitingIsAClearError(): void
    {
        // ÉTANT DONNÉ un navigateur qui n'a encore rien visité
        $visited = [];
        $browser = $this->browser(new FakePage(), $visited);

        // QUAND on clique
        // ALORS une erreur explicite demande d'appeler visit() d'abord
        $this->expectException(ProbeException::class);
        $this->expectExceptionMessage('visit() doit être appelé en premier');

        $browser->click(new Target('#a', '#a', null, false));
    }

    /**
     * @param list<string> $visited
     */
    private function browser(FakePage $page, array &$visited): PlaywrightBrowser
    {
        return new PlaywrightBrowser(
            function (string $path) use ($page, &$visited): object {
                $visited[] = $path;

                return $page;
            },
            static fn (string $table): int => 42,
            new PlaywrightActions(timeoutMs: 40, pollMs: 1),
        );
    }
}
