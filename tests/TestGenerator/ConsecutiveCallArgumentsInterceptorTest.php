<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Interceptor\ConsecutiveCallArgumentsInterceptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

#[CoversClass(ConsecutiveCallArgumentsInterceptor::class)]
final class ConsecutiveCallArgumentsInterceptorTest extends TestCase
{
    private const REPORT = <<<'TXT'
There was 1 failure:

1) App\Tests\Dynamic\PortfolioServiceDynamicTest::testGetPerformanceDataWithNoSnapshots
Expectation failed for method name is "findOneBy" when invoked 2 times
Parameter 1 for invocation Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryProxy::findOneBy([...], [...]): object does not match expected value.
Failed asserting that two arrays are equal.
--- Expected
+++ Actual
@@ @@
 Array (
-    'createdAt' => 'ASC'
+    'createdAt' => 'DESC'
 )

{SERVICE}:{LINE}
/project/tests/Dynamic/PortfolioServiceDynamicTest.php:385
TXT;

    private function report(string $serviceFile = '/nonexistent/src/Service/Demo.php', int $line = 319): string
    {
        return str_replace(['{SERVICE}', '{LINE}'], [$serviceFile, (string) $line], self::REPORT);
    }

    #[Test]
    public function parameterMismatchOnAMethodExpectedSeveralTimesIsSupported(): void
    {
        // QUAND
        $supported = (new ConsecutiveCallArgumentsInterceptor())->supports($this->report());

        // ALORS
        self::assertTrue($supported);
    }

    #[Test]
    public function callCountMismatchIsNotSupported(): void
    {
        // ÉTANT DONNÉ
        $report = "1) App\\Tests\\Dynamic\\DemoTest::testX\n"
            . "Expectation failed for method name is \"calculateCacTargetForPosition\" when invoked 2 times.\n"
            . "Method was expected to be called 2 times, actually called 1 time.\n";

        // QUAND
        $supported = (new ConsecutiveCallArgumentsInterceptor())->supports($report);

        // ALORS
        self::assertFalse($supported);
    }

    #[Test]
    public function parameterMismatchOnASingleExpectedCallIsNotSupported(): void
    {
        // ÉTANT DONNÉ
        $report = "1) App\\Tests\\Dynamic\\DemoTest::testX\n"
            . "Expectation failed for method name is \"save\" when invoked 1 time\n"
            . "Parameter 0 for invocation App\\Repository\\DemoRepository::save(Object App\\Entity\\Demo Object (...)): "
            . "object does not match expected value.\n";

        // QUAND
        $supported = (new ConsecutiveCallArgumentsInterceptor())->supports($report);

        // ALORS
        self::assertFalse($supported);
    }

    #[Test]
    public function hintNamesTheMethodTheArgumentAndTheDifference(): void
    {
        // QUAND
        $hint = (new ConsecutiveCallArgumentsInterceptor())->intercept($this->report());

        // ALORS
        self::assertNotNull($hint);
        self::assertStringContainsString('`findOneBy` est attendue 2 fois (`self::exactly(2)`)', $hint);
        self::assertStringContainsString("l'argument n°2", $hint);
        self::assertStringContainsString("attendu `'createdAt' => 'ASC'`, reçu `'createdAt' => 'DESC'`", $hint);
        self::assertStringContainsString('withConsecutive()', $hint);
        self::assertStringContainsString('willReturnCallback', $hint);
        self::assertStringContainsString("<valeur renvoyée par l'appel 2>", $hint);
        self::assertStringNotContainsString('Appel en échec dans le code testé', $hint);
    }

    /**
     * @throws RandomException
     */
    #[Test]
    public function hintQuotesTheCallingStatementWhenTheServiceFileIsReadable(): void
    {
        // ÉTANT DONNÉ un fichier de service dont l'appel en échec commence à la ligne 3
        $file = sys_get_temp_dir() . '/mika_interceptor_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, implode("\n", [
            '<?php',
            '',
            '$snapshot = $this->repository->findOneBy(',
            "    ['owner' => \$user],",
            "    ['createdAt' => 'DESC'],",
            ');',
            '$other = 1;',
        ]));

        try {
            // QUAND
            $hint = (new ConsecutiveCallArgumentsInterceptor())->intercept($this->report($file, 3));
        } finally {
            unlink($file);
        }

        // ALORS
        self::assertNotNull($hint);
        self::assertStringContainsString('ligne 3 de `' . basename($file) . '`', $hint);
        self::assertStringContainsString("findOneBy( ['owner' => \$user], ['createdAt' => 'DESC'], );", $hint);
    }
}
