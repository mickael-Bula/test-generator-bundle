<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Interceptor\Support\LateExpectationPreFixer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountAnalyzer;
use Mika\TestGeneratorBundle\Interceptor\Support\SutPropertyFinder;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LateExpectationPreFixer::class)]
final class LateExpectationPreFixerTest extends TestCase
{
    private const INPUT = __DIR__.'/Fixture/late-expectation-input.php.txt';

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function lateTests(): iterable
    {
        yield 'once()' => ['testLogDeleteStoresOneRecord', 1];
        yield 'exactly(2)' => ['testLogUpdateStoresOneRecordPerChangedField', 2];
        yield 'chaîne multiligne avec with()' => ['testLogUpdateWithSingleFieldChange', 1];
        yield 'with(callback(...))' => ['testLogDeleteStoresDeleteRecord', 1];
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    #[DataProvider('lateTests')]
    public function testExpectationIsMovedBeforeTheSutCall(string $test, int $times): void
    {
        // ÉTANT DONNÉ
        $code = (string) file_get_contents(self::INPUT);
        $report = $this->report($test, $times);

        // QUAND
        $fixed = $this->preFixer()->fix($report, $code);

        // ALORS
        self::assertNotNull($fixed, 'aucun correctif produit pour '.$test);
        self::assertSame(1, $this->callsNamed($fixed, $test, 'expects'), 'attente dupliquée ou perdue');
        self::assertTrue($this->expectationPrecedesCall($fixed, $test), 'attente toujours après l\'appel du SUT');
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testInsertedLinesFollowTheFileLineEndings(): void
    {
        // ÉTANT DONNÉ
        $raw = (string) file_get_contents(self::INPUT);
        $code = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));

        // QUAND
        $fixed = $this->preFixer()->fix($this->report('testLogDeleteStoresOneRecord', 1), $code);

        // ALORS
        self::assertNotNull($fixed);
        self::assertSame(0, preg_match_all('/(?<!\r)\n/', $fixed), 'fin de ligne LF isolée dans un fichier CRLF');
    }

    /**
     * @throws \ReflectionException
     */
    #[Test]
    public function testAnalyzerFindsAMovableExpectationForTheCallbackCase(): void
    {
        // ÉTANT DONNÉ
        $code = (string) file_get_contents(self::INPUT);
        $analyzer = new MockCountAnalyzer(
            (new ParserFactory())->createForNewestSupportedVersion(),
            new CoveredClassReader(),
            new SutPropertyFinder(),
        );

        // QUAND
        $findings = $analyzer->analyze($this->report('testLogDeleteStoresDeleteRecord', 1), $code);

        // ALORS
        self::assertCount(1, $findings, 'rapport non reconnu ou test introuvable');
        self::assertNotNull($findings[0]->expectation, 'attente introuvable ou non unique pour persist');
        self::assertNotNull($findings[0]->moveBefore, 'attente trouvée mais non déplaçable (movable) ou action introuvable');
    }

    private function preFixer(): LateExpectationPreFixer
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        return new LateExpectationPreFixer(
            new MockCountAnalyzer($parser, new CoveredClassReader(), new SutPropertyFinder()),
        );
    }

    private function report(string $test, int $times): string
    {
        $word = 1 === $times ? 'time' : 'times';

        return implode("\n", [
            'There was 1 failure:',
            '',
            sprintf('1) App\Tests\Dynamic\AuditLoggerDynamicTest::%s', $test),
            sprintf('Expectation failed for method name is "persist" when invoked %d %s.', $times, $word),
            sprintf('Method was expected to be called %d %s, actually called 0 times.', $times, $word),
            '',
            'FAILURES!',
        ]);
    }

    /**
     * @return list<Node\Stmt>
     */
    private function statementsOf(string $code, string $test): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $method = (new NodeFinder())->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $test,
        );
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        return $method->stmts ?? [];
    }

    private function callsNamed(string $code, string $test, string $name): int
    {
        return count((new NodeFinder())->find(
            $this->statementsOf($code, $test),
            static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall
                && $n->name instanceof Node\Identifier && $n->name->toString() === $name,
        ));
    }

    private function expectationPrecedesCall(string $code, string $test): bool
    {
        $finder = new NodeFinder();
        $expects = null;
        $action = null;
        foreach ($this->statementsOf($code, $test) as $i => $statement) {
            foreach ($finder->findInstanceOf($statement, Node\Expr\MethodCall::class) as $call) {
                if (!$call->name instanceof Node\Identifier) {
                    continue;
                }
                if ('expects' === $call->name->toString()) {
                    $expects ??= $i;
                }
                if ($call->var instanceof Node\Expr\PropertyFetch && $call->var->name instanceof Node\Identifier
                    && 'sut' === $call->var->name->toString()) {
                    $action ??= $i;
                }
            }
        }

        return null !== $expects && null !== $action && $expects < $action;
    }
}
