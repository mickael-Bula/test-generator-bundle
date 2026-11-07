<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Interceptor\Support\ReportBlocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportBlocks::class)]
final class ReportBlocksTest extends TestCase
{
    private const REPORT = <<<'TXT'
PHPUnit 11.5.46 by Sebastian Bergmann and contributors.

There was 1 failure:

1) App\Tests\Dynamic\DemoDynamicTest::testA
Failed asserting that 1 is identical to 2.

/p/tests/Dynamic/DemoDynamicTest.php:20

--

1 test triggered 1 PHP warning:

1) /p/src/Service/Demo.php:10
Undefined array key "x"

Triggered by:

* App\Tests\Dynamic\DemoDynamicTest::testB
  /p/tests/Dynamic/DemoDynamicTest.php:30

FAILURES!
Tests: 2, Assertions: 2, Failures: 1, Warnings: 1.

ERREUR DE QUALITÉ (ANALYSE STATIQUE DU TEST) :

1) App\Tests\Dynamic\DemoDynamicTest::testC
[forbidden-comment] 1 commentaire(s) interdit(s) (lignes 5)
TXT;

    #[Test]
    public function eachProblemGetsItsOwnBlockWithItsSectionHeader(): void
    {
        // QUAND
        $byTest = ReportBlocks::byTest(self::REPORT);

        // ALORS
        self::assertSame(['testA', 'testB', 'testC'], array_keys($byTest));
        self::assertStringStartsWith("There was 1 failure:\n\n" . '1) App\Tests\Dynamic\DemoDynamicTest::testA', $byTest['testA']);
        self::assertStringStartsWith('1 test triggered 1 PHP warning:', $byTest['testB']);
        self::assertStringStartsWith('ERREUR DE QUALITÉ', $byTest['testC']);
    }

    #[Test]
    public function aWarningIsAttachedToTheTestThatTriggeredIt(): void
    {
        // QUAND
        $byTest = ReportBlocks::byTest(self::REPORT);

        // ALORS
        self::assertStringContainsString('Undefined array key "x"', $byTest['testB']);
        self::assertArrayNotHasKey('(avertissement)', $byTest);
    }

    #[Test]
    public function theSummaryLinesAreNotPartOfAnyBlock(): void
    {
        // ALORS
        foreach (ReportBlocks::byTest(self::REPORT) as $block) {
            self::assertStringNotContainsString('FAILURES!', $block);
            self::assertStringNotContainsString('Tests: 2', $block);
            self::assertStringNotContainsString("\n--", $block);
        }
    }

    #[Test]
    public function windowsLineEndingsGiveTheSameBlocks(): void
    {
        // ÉTANT DONNÉ
        $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", self::REPORT));

        // ALORS
        self::assertSame(ReportBlocks::byTest(self::REPORT), ReportBlocks::byTest($crlf));
    }

    #[Test]
    public function eachPhpStanLineIsABlockOfItsOwn(): void
    {
        // ÉTANT DONNÉ
        $output = "- Ligne 233 : Class App\\Entity\\Position constructor invoked with 2 parameters, 0 required.\n"
            . "- Ligne 243 : Call to an undefined method App\\X::y().\n";

        // QUAND
        $blocks = ReportBlocks::of($output);

        // ALORS
        self::assertCount(2, $blocks);
        foreach (array_keys($blocks) as $signature) {
            self::assertStringStartsWith('phpstan :: ', $signature);
        }
    }

    #[Test]
    public function aFatalErrorWithoutNumberedEntryGivesNoBlock(): void
    {
        // ALORS
        self::assertSame([], ReportBlocks::of('Class XDynamicTest cannot be found in /p/tests/Dynamic/XDynamicTest.php'));
    }
}
