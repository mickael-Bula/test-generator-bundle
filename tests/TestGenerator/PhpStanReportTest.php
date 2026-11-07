<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Quality\PhpStanReport;
use PHPUnit\Framework\TestCase;

final class PhpStanReportTest extends TestCase
{
    public function testByTestRattacheChaqueLigneALaMethodeQuiLaContient(): void
    {
        // ÉTANT DONNÉ les plages de deux tests et un rapport PHPStan à trois erreurs
        $ranges = ['testAlpha' => ['start' => 10, 'end' => 20], 'testBeta' => ['start' => 22, 'end' => 31]];
        $output = implode("\n", [
            '- Ligne 12 : Premier problème.',
            '- Ligne 30 : Deuxième problème.',
            '- Ligne 31 : Troisième problème.',
        ]);

        // QUAND on les regroupe par test
        $split = PhpStanReport::byTest($output, $ranges);

        // ALORS chaque erreur va au test qui contient sa ligne, bornes comprises
        self::assertSame([], $split['unattributed']);
        self::assertSame([
            'testAlpha' => '- Ligne 12 : Premier problème.',
            'testBeta' => "- Ligne 30 : Deuxième problème.\n- Ligne 31 : Troisième problème.",
        ], $split['tests']);
    }

    public function testByTestSignaleLesErreursHorsDesMethodesDeTest(): void
    {
        // ÉTANT DONNÉ une erreur avant le premier test et une ligne qui n'est pas au format attendu
        $ranges = ['testAlpha' => ['start' => 10, 'end' => 20]];
        $output = "- Ligne 5 : Propriété sans type.\r\n- Ligne 12 : Dans le test.\r\nConfiguration PHPStan invalide\r\n";

        // QUAND on les regroupe par test
        $split = PhpStanReport::byTest($output, $ranges);

        // ALORS ces lignes sont signalées comme non rattachables, et la ligne du test reste attribuée
        self::assertSame(['- Ligne 5 : Propriété sans type.', 'Configuration PHPStan invalide'], $split['unattributed']);
        self::assertSame(['testAlpha' => '- Ligne 12 : Dans le test.'], $split['tests']);
    }
}
