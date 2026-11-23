<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Probe\DomScanner;

final class DomScannerFixture
{
    public static function dashboard(): ScanResult
    {
        return DomScanner::parse((string) file_get_contents(__DIR__ . '/../Fixture/scan-dashboard.json'));
    }

    public static function panel(): ScanResult
    {
        return DomScanner::parse((string) file_get_contents(__DIR__ . '/../Fixture/scan-new-position-open.json'));
    }
}
