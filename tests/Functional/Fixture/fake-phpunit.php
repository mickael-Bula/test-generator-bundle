<?php

// Faux PHPUnit pour PhpUnitGeneratedTestRunnerTest : se comporte selon FAKE_PHPUNIT_MODE.

declare(strict_types=1);

$mode = getenv('FAKE_PHPUNIT_MODE') ?: 'ok';

switch ($mode) {
    case 'ok':
        echo "PHPUnit 11.0.0 by Sebastian Bergmann and contributors.\n\n.\n\nTime: 00:01.000, Memory: 10.00 MB\n\nOK (1 test, 2 assertions)\n";
        break;
    case 'skipped':
        echo "PHPUnit 11.0.0\n\nS\n\nOK, but some tests were skipped!\nTests: 1, Assertions: 0, Skipped: 1.\n";
        break;
    case 'issues':
        echo "PHPUnit 11.0.0\n\n.\n\nOK, but there were issues!\nTests: 1, Assertions: 1, Warnings: 1.\n";
        break;
    case 'failure':
        echo "PHPUnit 11.0.0\n\nF\n\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.\n";
        exit(1);
    case 'ok-but-exit-1':
        echo "OK (1 test, 1 assertion)\n";
        exit(1);
    case 'env':
        echo 'app_env=' . getenv('APP_ENV') . ';e2e=' . getenv('PLAYWRIGHT_E2E') . ';xdebug=' . getenv('XDEBUG_MODE')
            . ';probe=' . (false === getenv('MIKA_PROBE_SCENARIO') ? 'none' : 'set') . ';cwd=' . getcwd()
            . ';args=' . implode(' ', array_slice($argv, 1)) . "\nOK (1 test, 1 assertion)\n";
        break;
    case 'sleep':
        echo "début\n";
        sleep(10);
        break;
}
