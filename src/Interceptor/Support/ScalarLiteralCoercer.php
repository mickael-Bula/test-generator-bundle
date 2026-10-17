<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Node;

final class ScalarLiteralCoercer
{
    /**
     * Texte de remplacement du littéral, ou null s'il n'est pas convertible sans perte vers $expected.
     */
    public static function coerce(Node\Expr $value, string $expected): ?string
    {
        $literal = self::literal($value);
        $target = self::target($expected);
        if (null === $literal || null === $target || $literal[0] === $target) {
            return null;
        }
        [$type, $text] = $literal;

        return match ($target) {
            'string' => 'string' !== $type ? "'" . $text . "'" : null,
            'int' => match (true) {
                'string' === $type && 1 === preg_match('/^-?\d+$/', $text) => $text,
                'float' === $type && 1 === preg_match('/^(-?\d+)\.0+$/', $text, $m) => $m[1],
                default => null,
            },
            'float' => match (true) {
                'int' === $type => $text . '.0',
                'string' === $type && 1 === preg_match('/^-?\d+\.\d+$/', $text) => $text,
                'string' === $type && 1 === preg_match('/^-?\d+$/', $text) => $text . '.0',
                default => null,
            },
        };
    }

    /**
     * @return array{0: 'int'|'float'|'string', 1: string}|null type du littéral et son texte, sans guillemets
     */
    private static function literal(Node\Expr $value): ?array
    {
        $sign = '';
        if ($value instanceof Node\Expr\UnaryMinus) {
            $sign = '-';
            $value = $value->expr;
        }

        return match (true) {
            $value instanceof Node\Scalar\Int_ => ['int', $sign . $value->value],
            $value instanceof Node\Scalar\Float_ => self::float($value, $sign),
            $value instanceof Node\Scalar\String_ && '' === $sign => ['string', $value->value],
            default => null,
        };
    }

    /**
     * @return array{0: 'float', 1: string}|null null pour un exposant ou une forme `.5` : on ne devine pas
     */
    private static function float(Node\Scalar\Float_ $f, string $sign): ?array
    {
        $raw = str_replace('_', '', (string) $f->getAttribute('rawValue', (string) $f->value));

        return 1 === preg_match('/^\d+\.\d+$/', $raw) ? ['float', $sign . $raw] : null;
    }

    /**
     * `?string` et `string|null` donnent `string` ; une vraie union ou un type non scalaire donnent null.
     */
    private static function target(string $expected): ?string
    {
        $parts = array_filter(
            array_map('trim', explode('|', ltrim(trim($expected), '?'))),
            static fn (string $p): bool => 'null' !== strtolower($p),
        );
        $only = 1 === \count($parts) ? strtolower((string) reset($parts)) : null;

        return \in_array($only, ['string', 'int', 'float'], true) ? $only : null;
    }
}