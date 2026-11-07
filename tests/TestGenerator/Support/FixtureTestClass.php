<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Support;

/** Fabrique de classes de test de fixture : une méthode est rouge dès qu'on lui donne un message d'échec. */
final class FixtureTestClass
{
    public const SUT_CODE = <<<'CODE'
        <?php

        namespace App\Service;

        final class Foo
        {
            public function value(): int
            {
                return 1;
            }
        }
        CODE;

    private const METHOD_TEMPLATE = <<<'CODE'
        public function {NAME}(): void
        {
            $value = 1;
            $read = $value;
            {ASSERTION}
        }
        CODE;

    private const CLASS_TEMPLATE = <<<'CODE'
        <?php

        declare(strict_types=1);

        namespace App\Tests\Dynamic;

        use App\Service\Foo;
        use PHPUnit\Framework\Attributes\CoversClass;
        use PHPUnit\Framework\TestCase;

        #[CoversClass(Foo::class)]
        final class FooDynamicTest extends TestCase
        {
        {METHODS}
        }
        CODE;

    /**
     * Méthode verte, ou rouge avec le message donné (le faux runner la détecte via self::fail).
     */
    public static function method(string $name, ?string $failure = null): string
    {
        $assertion = null === $failure ? 'self::assertSame(1, $read);' : sprintf("self::fail('%s');", $failure);

        return str_replace(['{NAME}', '{ASSERTION}'], [$name, $assertion], self::METHOD_TEMPLATE);
    }

    /**
     * Méthode dont l'exécution provoque une erreur fatale (classe introuvable).
     */
    public static function fatalMethod(string $name): string
    {
        return str_replace(
            ['{NAME}', '{ASSERTION}'],
            [$name, 'self::assertSame(1, \App\Service\Missing::VALUE);'],
            self::METHOD_TEMPLATE,
        );
    }

    public static function build(string ...$methods): string
    {
        $body = implode("\n\n", array_map(
            static fn (string $method): string => preg_replace('/^(?=.)/m', '    ', $method) ?? $method,
            $methods,
        ));

        return str_replace('{METHODS}', $body, self::CLASS_TEMPLATE);
    }

    /**
     * Méthode ignorée pour méthode privée, comme celles que le pipeline laisse dans les classes générées.
     */
    public static function skippedMethod(string $name): string
    {
        return str_replace(
            ['{NAME}', '{ASSERTION}'],
            [$name, "self::markTestSkipped('Méthode secret est privée');"],
            self::METHOD_TEMPLATE,
        );
    }

    /**
     * Méthode verte pour PHPUnit, mais que le faux PHPStan rejette avec ce message.
     */
    public static function stanMethod(string $name, string $message): string
    {
        return str_replace(
            ['{NAME}', '{ASSERTION}'],
            [$name, sprintf('self::assertSame(1, $read, \'STAN[%s]\');', $message)],
            self::METHOD_TEMPLATE,
        );
    }

    /**
     * Propriété que le faux PHPStan rejette : une erreur hors de toute méthode de test.
     */
    public static function stanProperty(string $message): string
    {
        return sprintf('private string $note = \'STAN[%s]\';', $message);
    }
}
