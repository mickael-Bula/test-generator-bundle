<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Spec;

use Mika\TestGeneratorBundle\Spec\ExpectedValueGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpectedValueGuard::class)]
final class ExpectedValueGuardTest extends TestCase
{
    #[Test]
    public function testUnNombreCalculeSansPremisseEstRemplaceQuandUnMockDuSutEstRetire(): void
    {
        // ÉTANT DONNÉ un cas dont le mock du SUT a été retiré et dont l'attendu (1100) n'est dans aucune entrée
        $block = self::block(
            '`$user` = `{"totalPortfolio":"1000"}`',
            '  - `App\Repository\PositionRepository::findByStatusAndUser()` ➔ retourne `[{"quantity":5,"currentPrice":200,"lvcBuyPrice":180}]`',
            '{"total_equity":1100,"cash_amount":100}',
        );

        // QUAND on applique le garde
        $result = (new ExpectedValueGuard())->apply($block, true);

        // ALORS le littéral est remplacé par la consigne, le reste du cas est intact
        self::assertStringContainsString(ExpectedValueGuard::NOTICE, $result);
        self::assertStringNotContainsString('1100', $result);
        self::assertStringContainsString('`$user` = `{"totalPortfolio":"1000"}`', $result);
    }

    #[Test]
    public function testUnAttenduDejaPresentDansLesEntreesEstConserveMemeSansMock(): void
    {
        // ÉTANT DONNÉ un attendu dont les nombres (1000) sont ceux des entrées
        $block = self::block('`$user` = `{"totalPortfolio":"1000"}`', '', '{"total_equity":1000,"cash_amount":1000}');

        // QUAND on applique le garde à un cas orphelin
        $result = (new ExpectedValueGuard())->apply($block, true);

        // ALORS rien ne change
        self::assertSame($block, $result);
    }

    #[Test]
    public function testSansEntreeNumeriqueUnAttenduNonNulEstRemplaceMemeSansMockRetire(): void
    {
        // ÉTANT DONNÉ un cas sans aucun nombre dans les entrées ni les mocks, avec un attendu de 250
        $block = self::block(
            '`$user` = `[]`',
            '  - `App\Repository\PositionRepository::findByStatusUserAndCore()` ➔ retourne `[]`',
            '{"current_value":0,"target_value":250}',
        );

        // QUAND on applique le garde à un cas non orphelin
        $result = (new ExpectedValueGuard())->apply($block, false);

        // ALORS l'attendu est remplacé
        self::assertStringContainsString(ExpectedValueGuard::NOTICE, $result);
    }

    #[Test]
    public function testUnCalculAvecDesEntreesNumeriquesEtSansMockRetireEstConserve(): void
    {
        // ÉTANT DONNÉ un attendu calculé (1500) à partir d'entrées numériques, sans mock retiré
        $block = self::block('`$runningPositions` = `[{"quantity":5,"lvcBuyPrice":180},{"quantity":3,"lvcBuyPrice":200}]`', '', '1500');

        // QUAND on applique le garde
        $result = (new ExpectedValueGuard())->apply($block, false);

        // ALORS le littéral est conservé : un calcul ordinaire n'est pas suspect
        self::assertSame($block, $result);
    }

    #[Test]
    public function testDesZerosEtDesChainesSontToujoursConserves(): void
    {
        // ÉTANT DONNÉ un attendu fait de zéros et de tableaux vides, puis un attendu textuel
        $zeros = self::block('`$user` = `[]`', '', '{"total_equity":0,"chart":[]}');
        $text = self::block('`$percentage` = `80`', '', '#ff4b5c');

        // QUAND on applique le garde à des cas orphelins
        $guard = new ExpectedValueGuard();

        // ALORS rien ne change
        self::assertSame($zeros, $guard->apply($zeros, true));
        self::assertSame($text, $guard->apply($text, true));
    }

    private static function block(string $inputs, string $mocks, string $expected): string
    {
        return "#### `testX`\n* **Type** : `SUCCESS`\n* **Description** : cas\n* **Entrées** : " . $inputs . "\n"
            . ('' === $mocks ? '' : "* **Attentes Mocks** :\n" . $mocks . "\n")
            . '* **Retour attendu** : `' . $expected . "`\n";
    }
}
