<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\MockCountExpectationInterceptor;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountFinding;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockCountExpectationInterceptor::class)]
#[UsesClass(MockCountFinding::class)]
final class MockCountExpectationInterceptorTest extends TestCase
{
    private const PROOF = "aucun chemin de `getCoreStats()` n'atteint `findOneBy()` (méthodes parcourues : getCoreStats)";

    #[Test]
    public function testUnMockInjoignableDonneLaRegleNeverSeule(): void
    {
        // ÉTANT DONNÉ un seul finding portant la preuve « jamais appelé »
        $findings = [self::finding(neverCalled: self::PROOF)];

        // QUAND on fabrique l'indice.
        $hint = MockCountExpectationInterceptor::render($findings);

        // ALORS il demande never() et ne contredit pas cette règle
        self::assertStringContainsString('Remplace son matcher par `never()`', $hint);
        self::assertStringContainsString('quelles que soient les données', $hint);
        self::assertStringContainsString(self::PROOF, $hint);
        self::assertStringNotContainsString("ne supprime pas l'attente", $hint);
        self::assertStringNotContainsString('exactly(N)', $hint);
    }

    #[Test]
    public function testUnCasMixteDonneLesDeuxJeuxDeRegles(): void
    {
        // ÉTANT DONNÉ un mock injoignable et un autre dont le nombre n'est pas déductible
        $findings = [
            self::finding(test: 'testA', neverCalled: self::PROOF),
            self::finding(test: 'testB', observed: 1),
        ];

        // QUAND on fabrique l'indice.
        $hint = MockCountExpectationInterceptor::render($findings);

        // ALORS les deux règles figurent
        self::assertStringContainsString('Remplace son matcher par `never()`', $hint);
        self::assertStringContainsString("ne supprime pas l'attente", $hint);
        self::assertStringContainsString('exactly(N)', $hint);
    }

    #[Test]
    public function testSansFindingLesReglesHabituellesSeulesSontDonnees(): void
    {
        // ÉTANT DONNÉ aucun finding exploitable
        // QUAND on fabrique l'indice
        $hint = MockCountExpectationInterceptor::render([]);

        // ALORS les règles habituelles figurent, sans never()
        self::assertStringContainsString('exactly(N)', $hint);
        self::assertStringNotContainsString('never()', $hint);
    }

    #[Test]
    public function testUnNombreDeduitEstAnnonceAvecSaDerivation(): void
    {
        // ÉTANT DONNÉ un finding dont le code du SUT donne deux appels
        $findings = [self::finding(observed: 1, derived: 2, derivation: 'foreach : 2 élément(s) fourni(s) par le test', limit: 3)];

        // QUAND on fabrique l'indice.
        $hint = MockCountExpectationInterceptor::render($findings);

        // ALORS le nombre à écrire est donné
        self::assertStringContainsString('attendu 3 fois, appelé 1 fois', $hint);
        self::assertStringContainsString('donne 2 appel(s)', $hint);
        self::assertStringContainsString('écris `exactly(2)`', $hint);
    }

    #[Test]
    public function testUnExcesDAppelsRappelleLaLimite(): void
    {
        // ÉTANT DONNÉ un finding « trop d'appels » avec une limite de un
        $findings = [self::finding(kind: 'too-many', observed: null, limit: 1)];

        // QUAND on fabrique l'indice.
        $hint = MockCountExpectationInterceptor::render($findings);

        // ALORS la limite est rappelée
        self::assertStringContainsString("appelé plus souvent que l'attente (1)", $hint);
    }

    #[Test]
    public function testUneAttenteDeclareeApresLActionDemandeDeLaDeplacer(): void
    {
        // ÉTANT DONNÉ un mock jamais appelé dont l'attente est après l'action
        $findings = [self::finding(observed: 0, moveBefore: new Node\Stmt\Nop())];

        // QUAND on fabrique l'indice.
        $hint = MockCountExpectationInterceptor::render($findings);

        // ALORS l'indice demande de déplacer l'attente avant le QUAND
        self::assertStringContainsString('déplace-la AVANT le `// QUAND`', $hint);
        self::assertStringNotContainsString("Le SUT n'a jamais appelé", $hint);
    }

    #[Test]
    public function testUnMockJamaisAppeleSansPreuveDemandeDeVerifierLesDonnees(): void
    {
        // ÉTANT DONNÉ un mock jamais appelé, sans preuve de chemin ni ancre de déplacement
        $findings = [self::finding(observed: 0)];

        // QUAND on fabrique l'indice.
        $hint = MockCountExpectationInterceptor::render($findings);

        // ALORS l'indice renvoie vers les données du test
        self::assertStringContainsString("Le SUT n'a jamais appelé cette méthode", $hint);
        self::assertStringNotContainsString('never()', $hint);
    }

    private static function finding(
        string $kind = 'wrong-count',
        string $test = 'testScenario',
        ?int $observed = 0,
        ?int $derived = null,
        string $derivation = 'appel du SUT indéterminé',
        ?int $limit = 2,
        ?Node\Stmt $moveBefore = null,
        ?string $neverCalled = null,
    ): MockCountFinding {
        return new MockCountFinding(
            $kind,
            $test,
            'findOneBy',
            $observed,
            $derived,
            $derivation,
            null,
            $limit,
            null,
            $moveBefore,
            $neverCalled
        );
    }
}
