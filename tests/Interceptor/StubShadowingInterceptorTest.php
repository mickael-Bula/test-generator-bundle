<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\StubShadowingInterceptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StubShadowingInterceptor::class)]
final class StubShadowingInterceptorTest extends TestCase
{
    #[Test]
    public function testUnStubMasqueParSetUpGardeLeConseilSurSetUp(): void
    {
        // ÉTANT DONNÉ une seule entrée : un stub masqué par celui de setUp()
        $entries = [[
            'kind' => 'setup',
            'text' => '- `testA` : le stub de `repo::find` (ligne 40) est masqué par celui de `setUp()` (ligne 20).'
        ]];

        // QUAND on fabrique l'indice.
        $hint = StubShadowingInterceptor::render($entries);

        // ALORS le conseil sur setUp() est donné, sans le texte des doublons
        self::assertStringContainsString('retire du `setUp()` tout stub', $hint);
        self::assertStringNotContainsString('UNE seule attente', $hint);
        self::assertStringNotContainsString('Ne modifie pas `setUp()`', $hint);
    }

    #[Test]
    public function testUnStubDoubleDansLeTestNeConseillePasDeRetirerLesStubsDeSetUp(): void
    {
        // ÉTANT DONNÉ une seule entrée : une méthode stubée deux fois dans le test
        $entries = [[
            'kind' => 'duplicate',
            'text' => '- `testA` : `repo::findOneBy` est stubé deux fois (lignes 364 puis 376).'
        ]];

        // QUAND on fabrique l'indice.
        $hint = StubShadowingInterceptor::render($entries);

        // ALORS le conseil demande une seule attente avec willReturnCallback et ne parle pas de retirer des stubs de setUp()
        self::assertStringContainsString('UNE seule attente', $hint);
        self::assertStringContainsString('willReturnCallback', $hint);
        self::assertStringContainsString('Ne modifie pas `setUp()`', $hint);
        self::assertStringNotContainsString('retire du `setUp()`', $hint);
        self::assertStringNotContainsString('seul le premier compte', $hint);
    }

    #[Test]
    public function testLesDeuxCasDonnentLesDeuxConseils(): void
    {
        // ÉTANT DONNÉ une entrée de chaque sorte
        $entries = [
            ['kind' => 'setup', 'text' => '- `testA` : masqué'],
            ['kind' => 'duplicate', 'text' => '- `testB` : doublé'],
        ];

        // QUAND on fabrique l'indice.
        $hint = StubShadowingInterceptor::render($entries);

        // ALORS les deux conseils figurent
        self::assertStringContainsString('retire du `setUp()` tout stub', $hint);
        self::assertStringContainsString('UNE seule attente', $hint);
    }
}
