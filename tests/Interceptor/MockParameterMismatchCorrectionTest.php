<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\MockParameterMismatchInterceptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MockParameterMismatchInterceptor::class)]
final class MockParameterMismatchCorrectionTest extends TestCase
{
    #[Test]
    public function testUneSeuleAttenteSansPluieursAppelsGardeLeTexteHabituel(): void
    {
        // ÉTANT DONNÉ une méthode appelée une seule fois
        // QUAND on fabrique la correction
        $text = MockParameterMismatchInterceptor::correction(true, []);

        // ALORS le texte d'origine est conservé
        self::assertStringContainsString('aligne `with(...)`', $text);
        self::assertStringNotContainsString('exactly(', $text);
    }

    #[Test]
    public function testUneAttenteDejaEgaleAuNombreDAppelsGardeLaConsigneHabituelle(): void
    {
        // ÉTANT DONNÉ une méthode attendue deux fois, deux appels dans le SUT
        // QUAND on fabrique la correction
        $text = MockParameterMismatchInterceptor::correction(false, ['findOneBy' => 2]);

        // ALORS la recette est donnée avec exactly(2) et la consigne de ne pas changer le nombre d'appels
        self::assertStringContainsString("->method('findOneBy')->willReturnCallback(", $text);
        self::assertStringContainsString('exactly(2)', $text);
        self::assertStringContainsString("Ne modifie ni le nombre d'appels attendus", $text);
        self::assertStringNotContainsString('UNIQUE attente', $text);
    }

    #[Test]
    public function testUnTestQuiAttendMoinsDAppelsQueLeSutEstInviteARegrouperSesAttentes(): void
    {
        // ÉTANT DONNÉ un test qui déclare des attentes séparées alors que le SUT appelle la méthode deux fois
        // QUAND on fabrique la correction
        $text = MockParameterMismatchInterceptor::correction(false, ['findOneBy' => 2], ['findOneBy' => 2]);

        // ALORS la recette est donnée, avec l'invitation à regrouper, et sans la consigne contraire
        self::assertStringContainsString("->method('findOneBy')->willReturnCallback(", $text);
        self::assertStringContainsString("remplace TOUTES les attentes `->method('findOneBy')`", $text);
        self::assertStringContainsString('UNIQUE attente `exactly(2)`', $text);
        self::assertStringContainsString("aucune condition ne les écarte", $text);
        self::assertStringNotContainsString("Ne modifie ni le nombre d'appels attendus", $text);
    }
}
