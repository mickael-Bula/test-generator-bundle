<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Support\FunctionalTestHelpersTrait;
use PHPUnit\Framework\TestCase;

/**
 * Classe de base E2E factice : les vraies aides du bundle (trait) sur une fausse page.
 */
abstract class ContractE2E extends TestCase
{
    use FunctionalTestHelpersTrait;

    public static FakePage $page;

    /** @var list<string> */
    public static array $logins = [];

    /** @var list<string|null> */
    public static array $strategies = [];

    /** @var list<string> */
    public static array $visited = [];

    /** @var array<string, list<int>> comptages successifs par table */
    public static array $counts = [];

    protected function loginAs(string $profile, ?string $strategy = null): void
    {
        self::$logins[] = $profile;
        self::$strategies[] = $strategy;
    }

    protected function visit(string $path): FakePage
    {
        self::$visited[] = $path;

        return self::$page;
    }

    protected function countRows(string $table): int
    {
        return array_shift(self::$counts[$table]) ?? throw new \LogicException('Comptage non prévu : ' . $table);
    }
}
