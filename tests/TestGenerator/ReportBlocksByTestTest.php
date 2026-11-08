<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Interceptor\Support\ReportBlocks;
use PHPUnit\Framework\TestCase;

final class ReportBlocksByTestTest extends TestCase
{
    public function testByTestRegroupeLesProblemesParTestTestsIgnoresCompris(): void
    {
        // ÉTANT DONNÉ un rapport PHPUnit avec erreurs, échecs, tests ignorés et un avertissement
        $report = <<<'TXT'
        PHPUnit 11.5.46 by Sebastian Bergmann and contributors.

        There were 5 errors:

        1) App\Tests\Dynamic\PositionManagerDynamicTest::testHandleUpperRangeTrailingWithWaitingEntrypoint
        Error: Call to undefined method App\Entity\User::expects()

        /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:282

        2) App\Tests\Dynamic\PositionManagerDynamicTest::testExecuteStrategySellWithNoMatchingStrategy
        PHPUnit\Framework\MockObject\IncompatibleReturnValueException: Method calculateGlobalPru may not return value of type int, its declared return type is "float"

        /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:454

        3) App\Tests\Dynamic\PositionManagerDynamicTest::testExecuteStrategySellWithMatchingStrategy
        PHPUnit\Framework\MockObject\IncompatibleReturnValueException: Method calculateGlobalPru may not return value of type int, its declared return type is "float"

        /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:502

        4) App\Tests\Dynamic\PositionManagerDynamicTest::testDeleteFormerWaitingPositionsWithActiveEntrypoints
        Error: Call to undefined method App\Entity\Entrypoint::expects()

        /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:817

        5) App\Tests\Dynamic\PositionManagerDynamicTest::testShouldNewPositionBeCoreWithLowCoreRatio
        TypeError: App\Entity\Position::setLvcCurrentPrice(): Argument #1 ($lvcCurrentPrice) must be of type ?string, int given, called in /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php on line 885

        /home/bulam/projects/webtrader_assetmapper/src/Entity/Position.php:269
        /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:885

        --

        There were 2 failures:

        1) App\Tests\Dynamic\PositionManagerDynamicTest::testCalculateGlobalPruWithRunningPositions
        Failed asserting that 101.66666666666667 is identical to 102.0.

        /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:556

        2) App\Tests\Dynamic\PositionManagerDynamicTest::testHandleWaitingPositionsWithValidCollection
        Expectation failed for method name is "calculateCacTargetForPosition" when invoked 2 times.
        Method was expected to be called 2 times, actually called 1 time.

        --

        There were 2 skipped tests:

        1) App\Tests\Dynamic\PositionManagerDynamicTest::testUpdatePositionsCurrentPriceWithNoRunningPositions
        Méthode updatePositionsCurrentPrice est privée

        2) App\Tests\Dynamic\PositionManagerDynamicTest::testProcessSingleDayWithNoUpperRangeAdjustment
        Méthode processSingleDay est privée

        --

        1 test triggered 1 PHP warning:

        1) /home/bulam/projects/webtrader_assetmapper/src/Service/PositionManager.php:654
        Undefined array key "total_equity"

        Triggered by:

        * App\Tests\Dynamic\PositionManagerDynamicTest::testShouldNewPositionBeCoreWithZeroEquity
          /home/bulam/projects/webtrader_assetmapper/tests/Dynamic/PositionManagerDynamicTest.php:862

        ERRORS!
        Tests: 34, Assertions: 36, Errors: 5, Failures: 2, Warnings: 1, Skipped: 2.
        TXT;

        // QUAND on regroupe le rapport par test
        $byTest = ReportBlocks::byTest($report);
        $names = array_keys($byTest);
        sort($names);

        // ALORS chaque test à problème a sa clé : les 8 en échec et les 2 tests ignorés
        self::assertSame([
            'testCalculateGlobalPruWithRunningPositions',
            'testDeleteFormerWaitingPositionsWithActiveEntrypoints',
            'testExecuteStrategySellWithMatchingStrategy',
            'testExecuteStrategySellWithNoMatchingStrategy',
            'testHandleUpperRangeTrailingWithWaitingEntrypoint',
            'testHandleWaitingPositionsWithValidCollection',
            'testProcessSingleDayWithNoUpperRangeAdjustment',
            'testShouldNewPositionBeCoreWithLowCoreRatio',
            'testShouldNewPositionBeCoreWithZeroEquity',
            'testUpdatePositionsCurrentPriceWithNoRunningPositions',
        ], $names);

        // ET les tests ignorés gardent leur en-tête de section : c'est ce qui permet à TestGeneratorV2 de ne pas les réparer
        foreach (['testProcessSingleDayWithNoUpperRangeAdjustment', 'testUpdatePositionsCurrentPriceWithNoRunningPositions'] as $skipped) {
            self::assertMatchesRegularExpression('/^There (?:was|were) \d+ skipped tests?:/', $byTest[$skipped]);
        }

        // ET l'avertissement est rattaché au test qui l'a déclenché
        $warningBlocks = implode("\n", (array) $byTest['testShouldNewPositionBeCoreWithZeroEquity']);
        self::assertStringContainsString('Undefined array key "total_equity"', $warningBlocks);
    }

    public function testByTestWithoutSkippedKeepsTheOtherBlocksOfASkippedTest(): void
    {
        // ÉTANT DONNÉ un test ignoré que la porte de qualité rejette aussi, et un test ignoré sans autre problème
        $report = <<<'TXT'
There were 2 skipped tests:

1) App\Tests\Dynamic\DemoDynamicTest::testSkippedAndRejected
Méthode build est privée

2) App\Tests\Dynamic\DemoDynamicTest::testSkippedOnly
Méthode helper est privée

ERREUR DE QUALITÉ (ANALYSE STATIQUE DU TEST) :

1) App\Tests\Dynamic\DemoDynamicTest::testSkippedAndRejected
[forbidden-comment] 3 commentaire(s) interdit(s) (lignes 16, 19, 22)
TXT;

        // QUAND
        $all = ReportBlocks::byTest($report);
        $withoutSkipped = ReportBlocks::byTest($report, includeSkipped: false);

        // ALORS
        self::assertEqualsCanonicalizing(['testSkippedAndRejected', 'testSkippedOnly'], array_keys($all));
        self::assertSame(['testSkippedAndRejected'], array_keys($withoutSkipped));
        self::assertStringStartsWith('ERREUR DE QUALITÉ', $withoutSkipped['testSkippedAndRejected']);
        self::assertStringNotContainsString('skipped tests', $withoutSkipped['testSkippedAndRejected']);
    }
}
