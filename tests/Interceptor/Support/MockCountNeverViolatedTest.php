<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Support;

use Mika\TestGeneratorBundle\Interceptor\MockCountExpectationInterceptor;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountAnalyzer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountFinding;
use Mika\TestGeneratorBundle\Interceptor\Support\SutPropertyFinder;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCountScenario;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockCountAnalyzer::class)]
#[CoversClass(MockCountExpectationInterceptor::class)]
#[UsesClass(MockCountFinding::class)]
#[UsesClass(CoveredClassReader::class)]
#[UsesClass(SutPropertyFinder::class)]
final class MockCountNeverViolatedTest extends TestCase
{
    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnNeverViolePourUnSutQuiAppelleLaMethodeEstReleve(): void
    {
        // ÉTANT DONNÉ un never() sur un mock que le SUT appelle deux fois (foreach sur deux éléments)
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::never()'), 'fetchAll([10, 20])');

        // QUAND l'analyse demande aussi les never() violés
        $findings = $this->analyzer()->analyze(self::neverReport(), $code, withNever: true);

        // ALORS le finding porte le nombre d'appels déduit du SUT
        self::assertCount(1, $findings);
        self::assertSame('never-violated', $findings[0]->kind);
        self::assertSame(2, $findings[0]->derived);
        self::assertNull($findings[0]->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testLesPreCorrecteursNeVoientPasLesNeverVioles(): void
    {
        // ÉTANT DONNÉ le même rapport
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::never()'), 'fetchAll([10, 20])');

        // QUAND l'analyse est appelée sans le drapeau (appel des pré-correcteurs)
        $findings = $this->analyzer()->analyze(self::neverReport(), $code);

        // ALORS rien n'est relevé
        self::assertSame([], $findings);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testUnNeverViolePrimeSurLaPreuveStatiqueDeNonAppel(): void
    {
        // ÉTANT DONNÉ un SUT dont l'analyse statique croit la dépendance injoignable, mais PHPUnit qui a vu l'appel
        $code = MockCountScenario::testCode('ReachabilityService', MockCountScenario::expectation('self::never()'), 'compute(3)');

        // QUAND l'analyse demande les never() violés
        $findings = $this->analyzer()->analyze(self::neverReport(), $code, withNever: true);

        // ALORS la mesure l'emporte : aucune preuve « jamais appelé » n'est avancée
        self::assertCount(1, $findings);
        self::assertNull($findings[0]->neverCalled);
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testLIndiceDemandeDeChangerLesDonneesOuLeMatcher(): void
    {
        // ÉTANT DONNÉ un never() violé
        $code = MockCountScenario::testCode('LoopService', MockCountScenario::expectation('self::never()'), 'fetchAll([10, 20])');
        $findings = $this->analyzer()->analyze(self::neverReport(), $code, withNever: true);

        // QUAND l'indice est rendu.
        $md = MockCountExpectationInterceptor::render($findings);

        // ALORS il propose les deux corrections et ne pousse pas à écrire exactly(N) d'office
        self::assertStringContainsString('`never()` violé : le SUT appelle bien la méthode avec les données du test.', $md);
        self::assertStringContainsString('Le code du SUT donne 2 appel(s)', $md);
        self::assertStringNotContainsString("- Écris l'attendu avec `exactly(N)`", $md);
    }

    private static function neverReport(): string
    {
        $report = (string) preg_replace('/ more than (?:once|\d+ times?)/', '', MockCountScenario::tooMany());
        self::assertStringContainsString('was not expected to be called.', $report);

        return $report;
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
