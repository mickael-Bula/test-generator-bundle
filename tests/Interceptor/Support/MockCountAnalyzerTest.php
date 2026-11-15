<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Support;

use Mika\TestGeneratorBundle\Interceptor\Support\MockCountAnalyzer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountFinding;
use Mika\TestGeneratorBundle\Interceptor\Support\SutPropertyFinder;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCountScenario;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockCountAnalyzer::class)]
#[UsesClass(MockCountFinding::class)]
#[UsesClass(CoveredClassReader::class)]
#[UsesClass(SutPropertyFinder::class)]
final class MockCountAnalyzerTest extends TestCase
{
    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnSutQuiNAtteintJamaisLaDependanceEstDeclareInjoignable(): void
    {
        // ÉTANT DONNÉ une méthode dont aucun chemin, même via ses méthodes privées, n'appelle fetch()
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::exactly(2)'), 'compute(3)');
        $report = MockCountScenario::wrongCount(2, 0);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS le finding porte la preuve statique et aucun nombre déduit
        self::assertCount(1, $findings);
        $finding = $findings[0];
        self::assertSame('wrong-count', $finding->kind);
        self::assertSame('testScenario', $finding->test);
        self::assertSame('fetch', $finding->method);
        self::assertSame(0, $finding->observed);
        self::assertSame(2, $finding->limit);
        self::assertNull($finding->derived);
        self::assertNotNull($finding->neverCalled);
        self::assertStringContainsString('méthodes parcourues : compute, double, offset', (string) $finding->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testLaDependanceAtteinteParUneMethodePriveeNEstPasDeclareeInjoignable(): void
    {
        // ÉTANT DONNÉ une méthode qui atteint fetch() par une méthode privée
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::once()'), 'run(1)');
        $report = MockCountScenario::wrongCount(1, 0);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS aucune preuve « jamais appelé », mais le nombre d'appels est déduit à travers la méthode privée
        self::assertCount(1, $findings);
        self::assertNull($findings[0]->neverCalled);
        self::assertSame(1, $findings[0]->derived);
        self::assertStringContainsString('lookup() : 1 appel(s)', $findings[0]->derivation);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnTestQuiAppelleLuiMemeLaMethodeDuMockNEstPasDeclareInjoignable(): void
    {
        // ÉTANT DONNÉ un test qui appelle lui-même fetch() sur le mock, en plus de l'attente
        $arrange = MockCountScenario::expectation('self::exactly(2)') . "\n        \$this->gatewayMock->fetch(1);";
        $code = MockCountScenario::testCode('ReachabilityService', $arrange, 'compute(3)');
        $report = MockCountScenario::wrongCount(2, 1);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS le SUT n'est pas déclaré injoignable : l'appel mesuré par PHPUnit vient du test
        self::assertCount(1, $findings);
        self::assertNull($findings[0]->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[DataProvider('provideUnprovableCases')]
    #[Test]
    public function testUneSituationOuLaPreuveNEstPasSureNeDonneAucunNeverCalled(string $sut, string $call): void
    {
        // ÉTANT DONNÉ un SUT où l'analyse statique ne peut pas conclure
        $code = MockCountScenario::testCode($sut, MockCountScenario::expectation('self::exactly(2)'), $call);
        $report = MockCountScenario::wrongCount(2, 0);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS le finding existe mais sans preuve « jamais appelé »
        self::assertCount(1, $findings);
        self::assertNull($findings[0]->neverCalled);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnprovableCases(): iterable
    {
        yield from MockCountScenario::unprovableCases();
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnScalairePasseEnArgumentNEmpecheNiLaPreuveNiLeParcours(): void
    {
        // ÉTANT DONNÉ une méthode qui ne passe à une autre méthode qu'une propriété scalaire
        $code = MockCountScenario::testCode('UnsafeService', MockCountScenario::expectation('self::exactly(2)'), 'passScalar()');
        $report = MockCountScenario::wrongCount(2, 0);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS la preuve est établie, avec les méthodes parcourues
        self::assertCount(1, $findings);
        self::assertStringContainsString('méthodes parcourues : passScalar, scale', (string) $findings[0]->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUneBoucleSurUnTableauLitteralDonneLeNombreDAppelsDeduit(): void
    {
        // ÉTANT DONNÉ un foreach sur un tableau de deux éléments fourni en littéral par le test
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::exactly(3)'), 'fetchAll([10, 20])');
        $report = MockCountScenario::wrongCount(3, 2);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS le nombre déduit est 2 et aucune preuve « jamais appelé » n'est avancée
        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->derived);
        self::assertSame(3, $findings[0]->limit);
        self::assertSame(2, $findings[0]->observed);
        self::assertNull($findings[0]->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnTableauVideDonneZeroAppelSansPreuveDeChemin(): void
    {
        // ÉTANT DONNÉ un foreach sur un tableau vide fourni en littéral
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::exactly(2)'), 'fetchAll([])');
        $report = MockCountScenario::wrongCount(2, 0);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze($report, $code);

        // ALORS zéro appel est déduit de la boucle, et non d'un chemin inatteignable
        self::assertCount(1, $findings);
        self::assertSame(0, $findings[0]->derived);
        self::assertNull($findings[0]->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnRapportSansProblemeDeComptageNeDonneAucunFinding(): void
    {
        // ÉTANT DONNÉ un rapport vert
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::once()'), 'compute(3)');

        // QUAND on l'analyse
        $findings = $this->analyzer()->analyze("OK (1 test, 1 assertion)\n", $code);

        // ALORS il n'y a rien à signaler
        self::assertSame([], $findings);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnCodeDeTestIllisibleNeDonneAucunFinding(): void
    {
        // ÉTANT DONNÉ un rapport de comptage mais un code de test qui ne se parse pas
        $report = MockCountScenario::wrongCount(2, 0);

        // QUAND on l'analyse
        $findings = $this->analyzer()->analyze($report, "<?php\nfunction {");

        // ALORS l'analyseur s'abstient
        self::assertSame([], $findings);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnAppelRelayeParUneAutreMethodePubliqueEstCompte(): void
    {
        // ÉTANT DONNÉ une méthode qui appelle une autre méthode publique, laquelle appelle fetch() une fois
        $code = MockCountScenario::testCode('ForwardingService', MockCountScenario::expectation('self::exactly(2)'), 'quantity(3)');

        // QUAND on analyse un rapport « attendu 2 fois, appelé 1 fois »
        $findings = $this->analyzer()->analyze(MockCountScenario::wrongCount(2, 1), $code);

        // ALORS le nombre déduit est 1, malgré le return anticipé placé après l'appel
        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->derived);
        self::assertNull($findings[0]->neverCalled);
        self::assertStringContainsString('snapshot() : 1 appel(s)', $findings[0]->derivation);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testDeuxAppelsRelayesSAdditionnent(): void
    {
        // ÉTANT DONNÉ une méthode qui appelle deux fois la méthode relais
        $code = MockCountScenario::testCode('ForwardingService', MockCountScenario::expectation('self::once()'), 'twice(3)');

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze(MockCountScenario::tooMany(), $code);

        // ALORS le nombre déduit est 2
        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->derived);
    }

    /**
     * @throws \ReflectionException
     */
    #[DataProvider('provideUnderivableRelays')]
    #[Test]
    public function testUnAppelRelayeNonProuveNeDonneAucunNombre(string $call, string $reason): void
    {
        // ÉTANT DONNÉ un appel relayé dont l'exécution n'est pas certaine
        $code = MockCountScenario::testCode('ForwardingService', MockCountScenario::expectation('self::exactly(2)'), $call);

        // QUAND on analyse le rapport
        $findings = $this->analyzer()->analyze(MockCountScenario::wrongCount(2, 1), $code);

        // ALORS aucun nombre n'est déduit, avec la raison
        self::assertCount(1, $findings);
        self::assertNull($findings[0]->derived);
        self::assertStringContainsString($reason, $findings[0]->derivation);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnderivableRelays(): iterable
    {
        yield 'return avant l\'appel' => ['guarded(3)', 'avant ou autour'];
        yield 'appel conditionnel' => ['conditional(3)', 'conditionnel'];
    }

    private function analyzer(): MockCountAnalyzer
    {
        return new MockCountAnalyzer(
            (new ParserFactory())->createForNewestSupportedVersion(),
            new CoveredClassReader(),
            new SutPropertyFinder(),
        );
    }
}
