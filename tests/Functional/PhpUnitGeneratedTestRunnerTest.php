<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Exception\VerificationException;
use Mika\TestGeneratorBundle\Functional\Verification\PhpUnitGeneratedTestRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpUnitGeneratedTestRunner::class)]
final class PhpUnitGeneratedTestRunnerTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = (string) tempnam(sys_get_temp_dir(), 'mika_generated_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->testFile)) {
            unlink($this->testFile);
        }
    }

    #[Test]
    public function testReportsAGreenTest(): void
    {
        // ÉTANT DONNÉ un PHPUnit qui termine par « OK (1 test, 2 assertions) »
        // QUAND on exécute le test généré
        $result = $this->runner('ok')->run($this->testFile);

        // ALORS il est vert et la sortie est conservée
        self::assertTrue($result->passed);
        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('OK (1 test, 2 assertions)', $result->output);
    }

    #[Test]
    #[DataProvider('notGreen')]
    public function testDoesNotCallAnythingButAPlainOkGreen(string $mode, int $exitCode): void
    {
        // ÉTANT DONNÉ un PHPUnit qui ignore le test, signale des problèmes, échoue ou sort en erreur malgré « OK »
        // QUAND on exécute le test généré
        $result = $this->runner($mode)->run($this->testFile);

        // ALORS il n'est pas considéré comme vert
        self::assertFalse($result->passed);
        self::assertSame($exitCode, $result->exitCode);
        self::assertFalse($result->timedOut);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function notGreen(): iterable
    {
        yield 'test ignoré' => ['skipped', 0];
        yield 'OK avec avertissements' => ['issues', 0];
        yield 'échec' => ['failure', 1];
        yield 'bilan OK mais code de sortie 1' => ['ok-but-exit-1', 1];
    }

    #[Test]
    public function testPassesTheHostEnvironmentAndTheTestFileAndDropsProbeVariables(): void
    {
        // ÉTANT DONNÉ des variables de sonde héritées de l'environnement courant
        putenv('MIKA_PROBE_SCENARIO=/tmp/ancien.md');

        try {
            // QUAND on exécute le test généré
            $result = $this->runner('env')->run($this->testFile);
        } finally {
            putenv('MIKA_PROBE_SCENARIO');
        }

        // ALORS l'environnement de test est positionné, les variables de sonde sont retirées, le projet est le dossier courant
        self::assertStringContainsString('app_env=test;e2e=1;xdebug=off;probe=none;cwd=' . realpath(__DIR__ . '/Fixture'), $result->output);
        self::assertStringContainsString('args=--colors=never --do-not-cache-result ' . $this->testFile, $result->output);
        self::assertTrue($result->passed);
    }

    #[Test]
    public function testInterruptsATestThatExceedsTheTimeout(): void
    {
        // ÉTANT DONNÉ un PHPUnit qui dort 10 secondes et un délai d'une seconde
        $started = microtime(true);

        // QUAND on exécute le test généré
        $result = $this->runner('sleep', 1)->run($this->testFile);

        // ALORS il est interrompu : rouge, avec indicateur de délai et ce qui avait été affiché
        self::assertFalse($result->passed);
        self::assertTrue($result->timedOut);
        self::assertStringContainsString('Délai de 1 s dépassé', $result->output);
        self::assertStringContainsString('début', $result->output);
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    #[Test]
    public function testRejectsAMissingTestFile(): void
    {
        // ÉTANT DONNÉ un fichier inexistant
        // QUAND on l'exécute
        // ALORS la vérification est impossible
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Test introuvable');

        $this->runner('ok')->run('/nonexistent/Test.php');
    }

    #[Test]
    public function testRejectsAMissingPhpunitBinary(): void
    {
        // ÉTANT DONNÉ un projet sans vendor/bin/phpunit
        $runner = new PhpUnitGeneratedTestRunner(__DIR__ . '/Fixture');

        // QUAND on exécute un test
        // ALORS l'erreur nomme le chemin attendu
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('PHPUnit introuvable : ' . __DIR__ . '/Fixture/vendor/bin/phpunit');

        $runner->run($this->testFile);
    }

    private function runner(string $mode, int $timeout = 20): PhpUnitGeneratedTestRunner
    {
        return new PhpUnitGeneratedTestRunner(
            __DIR__ . '/Fixture',
            timeoutSeconds: $timeout,
            environment: ['APP_ENV' => 'test', 'PLAYWRIGHT_E2E' => '1', 'XDEBUG_MODE' => 'off', 'FAKE_PHPUNIT_MODE' => $mode],
            phpunitCommand: [PHP_BINARY, __DIR__ . '/Fixture/fake-phpunit.php'],
        );
    }
}
