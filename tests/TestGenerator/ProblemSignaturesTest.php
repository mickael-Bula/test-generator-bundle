<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Interceptor\Support\ProblemSignatures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProblemSignatures::class)]
final class ProblemSignaturesTest extends TestCase
{
    #[Test]
    public function realPhpUnitReportIsParsed(): void
    {
        // ÉTANT DONNÉ
        $report = (string) file_get_contents(__DIR__ . '/Fixture/Report/report.log');

        // QUAND
        $signatures = ProblemSignatures::of($report);

        // ALORS
        self::assertCount(5, $signatures);
        self::assertTrue(ProblemSignatures::isComplete($report));
    }

    #[Test]
    public function escapeSequencesDoNotHideProblems(): void
    {
        // ÉTANT DONNÉ
        $report = "\x1b[41;37mERRORS!\x1b[0m\r\n1) App\\Tests\\Dynamic\\XDynamicTest::testA\r\nFailed asserting that 1 is identical to 2.\r\n\r\nTests: 1, Failures: 1.\r\n";

        // ALORS
        self::assertCount(1, ProblemSignatures::of($report));
        self::assertTrue(ProblemSignatures::isComplete($report));
    }
}
