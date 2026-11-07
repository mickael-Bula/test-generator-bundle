<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Spec\JsonRepair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonRepair::class)]
final class JsonRepairTest extends TestCase
{
    #[Test]
    public function validJsonIsLeftAlone(): void
    {
        // QUAND
        $result = JsonRepair::decode('{"a": 1, "b": "x"}');

        // ALORS
        self::assertSame(['a' => 1, 'b' => 'x'], $result['data']);
        self::assertSame([], $result['fixes']);
    }

    #[Test]
    public function bareExpressionsAreQuoted(): void
    {
        // ÉTANT DONNÉ
        $raw = <<<'JSON'
{
  "expectedBehavior": {
    "returnValue": 2 / 100,
    "other": round(1, 2),
    "count": 3
  }
}
JSON;

        // QUAND
        $result = JsonRepair::decode($raw);

        // ALORS
        self::assertSame('2 / 100', $result['data']['expectedBehavior']['returnValue']);
        self::assertSame('round(1, 2)', $result['data']['expectedBehavior']['other']);
        self::assertSame(3, $result['data']['expectedBehavior']['count']);
        self::assertCount(2, $result['fixes']);
    }

    #[Test]
    public function trailingCommasAreRemoved(): void
    {
        // QUAND
        $result = JsonRepair::decode('{"a": [1, 2,],}');

        // ALORS
        self::assertSame(['a' => [1, 2]], $result['data']);
        self::assertSame(['virgule en trop retirée'], $result['fixes']);
    }

    #[Test]
    public function unrepairableJsonKeepsTheOriginalError(): void
    {
        // ALORS
        $this->expectException(\JsonException::class);

        // QUAND
        JsonRepair::decode('{"a": ');
    }
}
