<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Support;

use Mika\TestGeneratorBundle\Service\PhpUnitTestRunner;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Faux PHPUnit : un test est rouge s'il appelle self::fail('message'),
 * ignoré s’il appelle self::markTestSkipped('message') et le rapport imite le format réel.
 * Un code qui contient « Missing::VALUE » produit une erreur fatale, sans bloc attribuable à un test.
 */
final readonly class MarkerPhpUnitRunner extends PhpUnitTestRunner
{
    /** @var \ArrayObject<int, string> codes exécutés, dans l'ordre */
    public \ArrayObject $runs;

    private Parser $parser;

    public function __construct(TestSnapshotStore $snapshots)
    {
        parent::__construct(sys_get_temp_dir(), $snapshots);
        $this->runs = new \ArrayObject();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function runTest(string $testCode, string $className): array
    {
        $this->runs->append($testCode);
        $file = sprintf('/project/tests/Dynamic/%sDynamicTest.php', $className);

        if (str_contains($testCode, 'Missing::VALUE')) {
            return ['success' => false, 'output' => implode("\n", [
                'PHP Fatal error:  Uncaught Error: Class "App\Service\Missing" not found in ' . $file . ':14',
                'Stack trace:',
                '#0 {main}',
                '  thrown in ' . $file . ' on line 14',
            ])];
        }

        $tests = $this->tests($testCode);
        if ([] === $tests) {
            return ['success' => false, 'output' => 'No tests executed!'];
        }

        $failures = [];
        $skipped = [];
        foreach ($tests as $name => $test) {
            if (1 === preg_match('/self::fail\(\'([^\']*)\'\)/', $test['source'], $matches)) {
                $failures[$name] = [$matches[1], $test['line']];
            } elseif (1 === preg_match('/self::markTestSkipped\(\'([^\']*)\'\)/', $test['source'], $matches)) {
                $skipped[$name] = $matches[1];
            }
        }

        $total = \count($tests);
        if ([] === $failures && [] === $skipped) {
            return ['success' => true, 'output' => sprintf('OK (%d tests, %d assertions)', $total, $total)];
        }

        $fqcn = 'App\Tests\Dynamic\\' . $className . 'DynamicTest';
        $sections = [];

        if ([] !== $failures) {
            $count = \count($failures);
            $lines = [1 === $count ? 'There was 1 failure:' : sprintf('There were %d failures:', $count), ''];
            $index = 0;
            foreach ($failures as $name => [$message, $line]) {
                array_push($lines, sprintf('%d) %s::%s', ++$index, $fqcn, $name), $message, '', sprintf('%s:%d', $file, $line), '');
            }
            $sections[] = implode("\n", $lines);
        }

        if ([] !== $skipped) {
            $count = \count($skipped);
            $lines = [1 === $count ? 'There was 1 skipped test:' : sprintf('There were %d skipped tests:', $count), ''];
            $index = 0;
            foreach ($skipped as $name => $message) {
                array_push($lines, sprintf('%d) %s::%s', ++$index, $fqcn, $name), $message, '');
            }
            $sections[] = implode("\n", $lines);
        }

        $summary = [] === $failures
            ? sprintf("OK, but there were issues!\nTests: %d, Assertions: %d, Skipped: %d.", $total, $total - \count($skipped), \count($skipped))
            : sprintf(
                "FAILURES!\nTests: %d, Assertions: %d, Failures: %d, Skipped: %d.",
                $total,
                $total - \count($failures) - \count($skipped),
                \count($failures),
                \count($skipped),
            );

        return [
            'success' => [] === $failures,
            'output' => "PHPUnit 11.5.46 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.3.6\n\n"
                . implode("\n--\n\n", $sections) . "\n" . $summary,
        ];
    }

    /**
     * @return array<string, array{source: string, line: int}>
     */
    private function tests(string $code): array
    {
        $tests = [];
        foreach ((new NodeFinder())->findInstanceOf($this->parser->parse($code) ?? [], ClassMethod::class) as $method) {
            $name = $method->name->toString();
            if (!str_starts_with($name, 'test')) {
                continue;
            }

            $start = $method->getStartFilePos();
            $tests[$name] = [
                'source' => substr($code, $start, $method->getEndFilePos() - $start + 1),
                'line' => $method->getStartLine(),
            ];
        }

        return $tests;
    }
}
