<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Interceptor\Fixture;

use Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount\Gateway;

/**
 * MockCountScenario.php fabrique le code de la classe de test analysée et les rapports PHPUnit.
 * Les deux tests partagent donc le même gabarit.
 */
final class MockCountScenario
{
    private const TEST_CLASS = 'App\Tests\Dynamic\ScenarioDynamicTest';

    private const TEMPLATE = <<<'CODE'
<?php

declare(strict_types=1);

namespace App\Tests\Dynamic;

use Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount\Gateway;
use Mika\TestGeneratorBundle\Tests\Interceptor\Fixture\MockCount\{SUT};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass({SUT}::class)]
final class {SUT}DynamicTest extends TestCase
{
    private Gateway&MockObject $gatewayMock;
    private {SUT} $sut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gatewayMock = $this->createMock(Gateway::class);
        $this->sut = new {SUT}($this->gatewayMock);
    }

    public function testScenario(): void
    {
        // ÉTANT DONNÉ
{ARRANGE}

        // QUAND
        $result = $this->sut->{CALL};

        // ALORS
        self::assertNotNull($result);
    }
}
CODE;

    /**
     * Classe de test analysée : le SUT, les lignes de « ÉTANT DONNÉ » et l'appel du SUT (ex. `compute(3)`).
     */
    public static function testCode(string $sut, string $arrange, string $call): string
    {
        return str_replace(['{SUT}', '{ARRANGE}', '{CALL}'], [$sut, $arrange, $call], self::TEMPLATE);
    }

    /**
     * Attente sur Gateway::fetch(), avec le matcher donné (`self::once()`, `$this->exactly(2)`…).
     */
    public static function expectation(string $matcher): string
    {
        return sprintf('        $this->gatewayMock->expects(%s)->method(\'fetch\')->willReturn(null);', $matcher);
    }

    /**
     * Rapport PHPUnit : « attendu N fois, appelé K fois ».
     */
    public static function wrongCount(int $expected, int $observed): string
    {
        return sprintf(
            "There was 1 failure:\n\n1) %s::testScenario\n"
            . "Expectation failed for method name is \"fetch\" when invoked %s.\n"
            . "Method was expected to be called %s, actually called %s.\n",
            self::TEST_CLASS,
            self::times($expected),
            self::times($expected),
            self::times($observed),
        );
    }

    /**
     * Rapport PHPUnit : « n'était pas attendu plus de N fois ».
     */
    public static function tooMany(string $limit = 'once'): string
    {
        return sprintf(
            "There was 1 failure:\n\n1) %s::testScenario\n%s::fetch(20): ?string was not expected to be called more than %s.\n",
            self::TEST_CLASS,
            Gateway::class,
            $limit,
        );
    }

    /**
     * Scénarios où la preuve « jamais appelé » doit être refusée : [SUT, appel du SUT dans le test].
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unprovableCases(): iterable
    {
        yield 'héritage' => ['InheritingService', 'compute()'];
        yield 'trait' => ['TraitService', 'compute()'];
        yield 'constructeur non trivial' => ['BuildingService', 'compute()'];
        yield 'méthode absente de la classe' => ['MagicService', 'compute()'];
        yield '$this passé en argument' => ['UnsafeService', 'leakThis()'];
        yield 'propriété objet passée en argument' => ['UnsafeService', 'passObject()'];
        yield 'appel dynamique par nom' => ['UnsafeService', "byName('compute')"];
        yield 'call_user_func' => ['UnsafeService', 'byCallable()'];
    }

    private static function times(int $n): string
    {
        return $n . ' time' . (1 === $n ? '' : 's');
    }
}
