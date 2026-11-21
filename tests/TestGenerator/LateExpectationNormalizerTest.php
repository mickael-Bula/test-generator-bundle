<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Interceptor\Support\LateExpectationNormalizer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountAnalyzer;
use Mika\TestGeneratorBundle\Interceptor\Support\SutPropertyFinder;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LateExpectationNormalizer::class)]
final class LateExpectationNormalizerTest extends TestCase
{
    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Tests\Dynamic;

use Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture\AuditLogger;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture\RecordStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerDynamicTest extends TestCase
{
    private RecordStoreInterface&MockObject $storeMock;
    private AuditLogger $sut;

    protected function setUp(): void
    {
        $this->storeMock = $this->createMock(RecordStoreInterface::class);
        $this->sut = new AuditLogger($this->storeMock);
    }

    #[Test]
    public function testScenario(): void
    {
__BODY__
    }
}

PHP;

    #[Test]
    public function testLateNeverIsMovedBeforeTheCall(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // ÉTANT DONNÉ
        $same = ['object' => 'Identique'];

        // QUAND
        $this->sut->logUpdate(1, $same, $same);

        // ALORS
        $this->storeMock->expects($this->never())->method('persist');
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNotNull($fixed);
        self::assertSame(1, substr_count($fixed, 'expects('), 'attente dupliquée ou perdue');
        self::assertLessThan(strpos($fixed, '->logUpdate('), strpos($fixed, 'expects('));
        self::assertLessThan(strpos($fixed, '// QUAND'), strpos($fixed, 'expects('), 'l\'attente doit précéder le marqueur QUAND');
    }

    #[Test]
    public function testTwoLateExpectationsKeepTheirOrder(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // ÉTANT DONNÉ
        $old = ['object' => 'A'];

        // QUAND
        $this->sut->logUpdate(1, $old, ['object' => 'B']);

        // ALORS
        $this->storeMock->expects($this->once())->method('persist');
        $this->storeMock->expects($this->any())->method('persist');
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNotNull($fixed);
        self::assertLessThan(strpos($fixed, 'any()'), strpos($fixed, 'once()'));
        self::assertLessThan(strpos($fixed, '->logUpdate('), strpos($fixed, 'any()'));
    }

    #[Test]
    public function testWellPlacedExpectationIsLeftAlone(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // ÉTANT DONNÉ
        $this->storeMock->expects($this->once())->method('persist');

        // QUAND
        $this->sut->logDelete(5);

        // ALORS
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNull($fixed);
    }

    #[Test]
    public function testExpectationBetweenTwoCallsIsLeftAlone(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // QUAND
        $this->sut->logDelete(1);
        $this->storeMock->expects($this->once())->method('persist');
        $this->sut->logDelete(2);

        // ALORS
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNull($fixed);
    }

    #[Test]
    public function testExpectationUsingAVariableDefinedAfterTheCallIsLeftAlone(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // QUAND
        $this->sut->logDelete(1);

        // ALORS
        $expected = ['subject' => 1, 'action' => 'DELETE'];
        $this->storeMock->expects($this->once())->method('persist')->with($expected);
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNull($fixed);
    }

    #[Test]
    public function testExpectationWithCallbackParameterIsMoved(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // QUAND
        $this->sut->logDelete(11);

        // ALORS
        $this->storeMock
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(static fn (array $record): bool => 'DELETE' === $record['action']));
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNotNull($fixed);
        self::assertLessThan(strpos($fixed, '->logDelete('), strpos($fixed, 'expects('));
    }

    #[Test]
    public function testInsertedLinesFollowTheFileLineEndings(): void
    {
        // ÉTANT DONNÉ
        $code = str_replace("\n", "\r\n", $this->source(<<<'PHP'
        // QUAND
        $this->sut->logDelete(1);

        // ALORS
        $this->storeMock->expects($this->never())->method('persist');
PHP));

        // QUAND
        $fixed = $this->normalizer()->normalize($code);

        // ALORS
        self::assertNotNull($fixed);
        self::assertSame(0, preg_match_all('/(?<!\r)\n/', $fixed), 'fin de ligne LF isolée dans un fichier CRLF');
    }

    private function source(string $body): string
    {
        return str_replace('__BODY__', $body, self::TEMPLATE);
    }

    private function normalizer(): LateExpectationNormalizer
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        return new LateExpectationNormalizer(
            $parser,
            new CoveredClassReader(),
            new SutPropertyFinder(),
            new MockCountAnalyzer($parser, new CoveredClassReader(), new SutPropertyFinder()),
        );
    }

    #[Test]
    public function testSkippedTestIsLeftAlone(): void
    {
        // ÉTANT DONNÉ
        $code = $this->source(<<<'PHP'
        // QUAND
        $this->sut->logDelete(1);

        // ALORS
        $this->storeMock->expects($this->never())->method('persist');
PHP);

        // QUAND
        $fixed = $this->normalizer()->normalize($code, ['testScenario']);

        // ALORS
        self::assertNull($fixed);
    }
}
