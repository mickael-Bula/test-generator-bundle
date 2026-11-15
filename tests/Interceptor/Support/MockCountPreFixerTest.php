<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Support;

use Mika\TestGeneratorBundle\Interceptor\Support\MockCountAnalyzer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountFinding;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountPreFixer;
use Mika\TestGeneratorBundle\Interceptor\Support\SutPropertyFinder;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCountScenario;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockCountPreFixer::class)]
#[UsesClass(MockCountAnalyzer::class)]
#[UsesClass(MockCountFinding::class)]
#[UsesClass(CoveredClassReader::class)]
#[UsesClass(SutPropertyFinder::class)]
final class MockCountPreFixerTest extends TestCase
{
    #[DataProvider('provideMatchersOfAnUnreachableMock')]
    #[Test]
    public function testUnMockInjoignableEtMesureANulEstRemplaceParNever(string $matcher, int $expected, string $replacement): void
    {
        // ÉTANT DONNÉ une attente sur une méthode que le SUT n'atteint jamais, et PHPUnit qui a mesuré zéro appel
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation($matcher), 'compute(3)');
        $report = MockCountScenario::wrongCount($expected, 0);

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix($report, $code);

        // ALORS seul le matcher change, avec son préfixe d'origine
        self::assertSame(str_replace($matcher, $replacement, $code), $fixed);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideMatchersOfAnUnreachableMock(): iterable
    {
        yield 'once' => ['self::once()', 1, 'self::never()'];
        yield 'exactly' => ['self::exactly(2)', 2, 'self::never()'];
        yield 'préfixe $this->' => ['$this->exactly(5)', 5, '$this->never()'];
    }

    #[Test]
    public function testUnExcesDAppelsNEstJamaisCorrigeEnNever(): void
    {
        // ÉTANT DONNÉ un rapport « appelé plus que prévu » sur un mock que l'analyse croit injoignable
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::once()'), 'compute(3)');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::tooMany(), $code);

        // ALORS il s'abstient : la preuve et la mesure se contredisent
        self::assertNull($fixed);
    }

    #[Test]
    public function testDesAppelsMesuresInterdisentLeNever(): void
    {
        // ÉTANT DONNÉ un SUT injoignable, mais PHPUnit qui a mesuré un appel
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::exactly(2)'), 'compute(3)');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(2, 1), $code);

        // ALORS il s'abstient
        self::assertNull($fixed);
    }

    #[Test]
    public function testUneDependanceAtteinteParUneMethodePriveeNEstPasCorrigee(): void
    {
        // ÉTANT DONNÉ une méthode qui atteint fetch() par une méthode privée, et zéro appel mesuré
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::once()'), 'run(1)');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(1, 0), $code);

        // ALORS il s'abstient : c'est aux données du test de changer
        self::assertNull($fixed);
    }

    #[DataProvider('provideUnprovableCases')]
    #[Test]
    public function testUneSituationOuLaPreuveNEstPasSureNEstPasCorrigee(string $sut, string $call): void
    {
        // ÉTANT DONNÉ un SUT où l'analyse statique ne peut pas conclure, et zéro appel mesuré
        $code = MockCountScenario::testCode($sut, MockCountScenario::expectation('self::exactly(2)'), $call);

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(2, 0), $code);

        // ALORS il s'abstient
        self::assertNull($fixed);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnprovableCases(): iterable
    {
        yield from MockCountScenario::unprovableCases();
    }

    #[Test]
    public function testUnNombreMesureEgalAuNombreDeduitEstCorrigeEnExactly(): void
    {
        // ÉTANT DONNÉ un foreach sur deux éléments, une attente exactly(3) et deux appels mesurés
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::exactly(3)'), 'fetchAll([10, 20])');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(3, 2), $code);

        // ALORS exactly(3) devient exactly(2) : le comportement d'origine est conservé
        self::assertSame(str_replace('self::exactly(3)', 'self::exactly(2)', $code), $fixed);
    }

    #[Test]
    public function testUnOnceDepasseEstCorrigeEnExactlyQuandLeCodePrevoitPlus(): void
    {
        // ÉTANT DONNÉ un foreach sur deux éléments et une attente once()
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::once()'), 'fetchAll([10, 20])');

        // QUAND le correcteur s'applique à un rapport « appelé plus souvent que prévu »
        $fixed = $this->fixer()->fix(MockCountScenario::tooMany(), $code);

        // ALORS once() devient exactly(2)
        self::assertSame(str_replace('self::once()', 'self::exactly(2)', $code), $fixed);
    }

    #[Test]
    public function testUnTableauVideNeProduitAucuneCorrection(): void
    {
        // ÉTANT DONNÉ une boucle sur un tableau vide : zéro appel déduit sans preuve de chemin
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::exactly(2)'), 'fetchAll([])');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(2, 0), $code);

        // ALORS il s'abstient : les données du test sont en cause
        self::assertNull($fixed);
    }

    #[Test]
    public function testUnNombreDejaJusteNEstPasReecrit(): void
    {
        // ÉTANT DONNÉ une attente exactly(2) égale au nombre déduit
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::exactly(2)'), 'fetchAll([10, 20])');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(2, 2), $code);

        // ALORS il s'abstient
        self::assertNull($fixed);
    }

    #[Test]
    public function testUnRapportSansProblemeDeComptageNeChangeRien(): void
    {
        // ÉTANT DONNÉ un rapport vert
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::once()'), 'compute(3)');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix("OK (1 test, 1 assertion)\n", $code);

        // ALORS il s'abstient
        self::assertNull($fixed);
    }

    #[Test]
    public function testUnAppelRelayeParUneAutreMethodeEstCorrigeQuandPhpunitConfirme(): void
    {
        // ÉTANT DONNÉ une attente exactly(2) alors que le code relayé appelle fetch() une fois, et PHPUnit qui a mesuré un appel
        $code = MockCountScenario::testCode('ForwardingService', MockCountScenario::expectation('self::exactly(2)'), 'quantity(3)');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(2, 1), $code);

        // ALORS exactly(2) devient once()
        self::assertSame(str_replace('self::exactly(2)', 'self::once()', $code), $fixed);
    }

    #[Test]
    public function testUnReturnAvantLAppelRelayeInterditLaCorrection(): void
    {
        // ÉTANT DONNÉ un return anticipé placé avant l'appel relayé
        $code = MockCountScenario::testCode('ForwardingService', MockCountScenario::expectation('self::exactly(2)'), 'guarded(3)');

        // QUAND le correcteur s'applique
        $fixed = $this->fixer()->fix(MockCountScenario::wrongCount(2, 1), $code);

        // ALORS il s'abstient : la preuve n'est pas établie
        self::assertNull($fixed);
    }

    private function fixer(): MockCountPreFixer
    {
        return new MockCountPreFixer(new MockCountAnalyzer(
            (new ParserFactory())->createForNewestSupportedVersion(),
            new CoveredClassReader(),
            new SutPropertyFinder(),
        ));
    }
}
