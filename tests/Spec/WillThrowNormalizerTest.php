<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Spec;

use Mika\TestGeneratorBundle\Spec\WillThrowNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WillThrowNormalizer::class)]
final class WillThrowNormalizerTest extends TestCase
{
    #[Test]
    public function testNormalizeKeepsStringAndNullWillThrow(): void
    {
        // ÉTANT DONNÉ
        $spec = ['mockExpectations' => [
            ['method' => 'a', 'willThrow' => null],
            ['method' => 'b', 'willThrow' => 'RuntimeException'],
        ]];
        $normalizer = new WillThrowNormalizer();

        // QUAND
        $result = $normalizer->normalize($spec);

        // ALORS
        $this->assertSame($spec, $result);
    }

    #[Test]
    public function testNormalizeReducesObjectToItsClassName(): void
    {
        // ÉTANT DONNÉ
        $spec = [
            'methods' => [
                ['testCases' => [
                    ['mockExpectations' => [
                        [
                            'dependency' => 'App\Service\PortfolioService',
                            'willReturns' => ['total_equity' => 1000.5],
                            'willThrow' => ['class' => 'Exception', 'message' => 'Erreur de calcul'],
                            ]
                    ]]
                ]]
            ]
        ];
        $normalizer = new WillThrowNormalizer();

        // QUAND
        $result = $normalizer->normalize($spec);

        // ALORS
        $this->assertSame(
            [
                'methods' => [
                    ['testCases' => [
                        ['mockExpectations' => [
                            [
                                'dependency' => 'App\Service\PortfolioService',
                                'willReturns' => ['total_equity' => 1000.5],
                                'willThrow' => 'Exception',
                            ]
                        ]]
                    ]]
                ]
            ], $result);
    }

    #[Test]
    public function testNormalizeTurnsObjectWithoutClassIntoNull(): void
    {
        // ÉTANT DONNÉ
        $spec = ['mockExpectations' => [
            ['willThrow' => ['message' => 'sans classe']]
        ]];
        $normalizer = new WillThrowNormalizer();

        // QUAND
        $result = $normalizer->normalize($spec);

        // ALORS
        $this->assertSame(
            ['mockExpectations' => [
                ['willThrow' => null]
            ]],
            $result
        );
    }
}
