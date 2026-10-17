<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Command\CaptureFixtureCommand;
use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PreFixerChain::class)]
final class FailedTestFixturesTest extends KernelTestCase
{
    private const DIR = __DIR__ . '/../Fixtures/FailedTests';

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        $inputs = array_merge(
            glob(self::DIR . '/*/input.php') ?: [],      // cas en une passe
            glob(self::DIR . '/*/*/input.php') ?: [],    // étapes 01, 02, … d'un scénario
        );
        foreach ($inputs as $input) {
            $dir = \dirname($input);
            yield substr($dir, \strlen(self::DIR) + 1) => [$dir]; // ex. ma-classe--run-1/02
        }
    }

    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach (glob(self::DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function stepsAreChained(string $scenarioDir): void
    {
        // ÉTANT DONNÉ
        $steps = glob($scenarioDir . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [];

        // ALORS
        self::addToAssertionCount(1); // un cas en une passe n'a pas d'étapes
        for ($i = 1; $i < \count($steps); ++$i) {
            self::assertFileExists($steps[$i - 1] . '/expected.php', 'Une étape suivante existe : la précédente doit avoir un expected.php.');
            self::assertSame(
                (string) file_get_contents($steps[$i - 1] . '/expected.php'),
                (string) file_get_contents($steps[$i] . '/input.php'),
                sprintf('%s/input.php doit égaler %s/expected.php : recapturez le scénario avec --force.', basename($steps[$i]), basename($steps[$i - 1])),
            );
        }
    }

    #[Test]
    #[DataProvider('cases')]
    public function fixtureBehavesAsRecorded(string $dir): void
    {
        // ÉTANT DONNÉ
        self::bootKernel();
        $container = static::getContainer();
        $chain = $container->get(PreFixerChain::class);
        $interceptors = $container->get(ErrorInterceptorRegistry::class);
        $snapshots = $container->get(TestSnapshotStore::class);

        $input = (string) file_get_contents($dir . '/input.php');
        $report = str_replace(
            CaptureFixtureCommand::PLACEHOLDER,
            self::$kernel->getProjectDir(),
            (string) file_get_contents($dir . '/report.log'),
        );
        $stale = $this->stalenessNote($dir);

        // Le runner supprime le test après exécution : on recrée l'instantané que les intercepteurs relisent
        if (1 === preg_match('#(/\S+Test\.php):\d+#', $report, $m)) {
            $snapshots->save($m[1], $input);
        }

        // QUAND
        $patched = $chain->fix($report, $input);
        $hints = (string) $interceptors->resolveFromErrorOutput($report);

        // ALORS
        $expectedFile = $dir . '/expected.php';

        if (false !== getenv('UPDATE_FIXTURES')) { // mode mise à jour : relisez ensuite `git diff`
            null === $patched ? @unlink($expectedFile) : file_put_contents($expectedFile, $patched);
            self::addToAssertionCount(1);

            return;
        }

        if (is_file($expectedFile)) {
            self::assertSame((string) file_get_contents($expectedFile), $patched, 'Le code corrigé diffère de expected.php.' . $stale);
        } else {
            self::assertNull($patched, "Aucun correcteur ne devait s'appliquer (pas de expected.php)." . $stale);
        }

        if (is_file($dir . '/hints.txt')) {
            foreach (file($dir . '/hints.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $needle) {
                self::assertStringContainsString($needle, $hints, 'Indice absent : ' . $needle . $stale);
            }
        }
    }

    private function stalenessNote(string $dir): string
    {
        if (!is_file($dir . '/meta.json')) {
            return '';
        }
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($meta['sut'], $meta['sut_sha1']) || !class_exists($meta['sut'])) {
            return '';
        }
        $file = (new \ReflectionClass($meta['sut']))->getFileName();

        return false !== $file && sha1_file($file) !== $meta['sut_sha1']
            ? sprintf(' (la classe %s a changé depuis la capture : recapturez ce cas)', $meta['sut'])
            : '';
    }
}