<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Support\FunctionalTestHelpersTrait;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ContractE2EBase;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakePage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionalTestHelpersTrait::class)]
final class FunctionalTestHelpersTraitTest extends TestCase
{
    #[Test]
    public function testWaitUntilUrlLeavesFollowsTheLoginRedirect(): void
    {
        // ÉTANT DONNÉ une classe de test E2E et une page redirigée de /login vers l'accueil à la deuxième lecture
        $host = $this->host();
        $page = new FakePage(['http://localhost/login', 'http://localhost/']);

        // QUAND on attend qu'elle quitte /login
        $host->leave($page, '/login');

        // ALORS l'attente se termine sans erreur
        self::assertSame('http://localhost/', $page->url());
    }

    #[Test]
    public function testWaitUntilUrlLeavesFailsWhenTheLoginIsRefused(): void
    {
        // ÉTANT DONNÉ une page qui reste sur /login
        $host = $this->host();

        // QUAND on attend qu'elle la quitte
        // ALORS l'échec arrive au délai de la classe de test (400 ms)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('la page est restée sur « /login »');

        $host->leave(new FakePage(['http://localhost/login']), '/login');
    }

    private function host(): object
    {
        return new class('leave') extends ContractE2EBase {
            protected function functionalTimeoutMs(): int
            {
                return 400;
            }

            public function leave(object $page, string $path): void
            {
                $this->waitUntilUrlLeaves($page, $path);
            }
        };
    }
}
