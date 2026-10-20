<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final class SourceSpan
{
    /**
     * @return array{0: int, 1: int} [début, longueur] des lignes complètes couvrant [$start, $end]
     */
    public static function lines(string $code, int $start, int $end): array
    {
        while ($start > 0 && \in_array($code[$start - 1], [' ', "\t"], true)) {
            --$start; // reprend l'indentation
        }
        $length = $end - $start + 1;

        // Précédée d'une ligne vide : on avale aussi les lignes vides qui suivent (jamais de double ligne vide)
        $before = substr($code, max(0, $start - 200), min($start, 200));
        $pattern = 1 === preg_match('/\R[ \t]*\R\z/', $before) ? '/\G(?:[ \t]*\R)+/' : '/\G[ \t]*\R/';
        if (1 === preg_match($pattern, $code, $m, 0, $end + 1)) {
            $length += \strlen($m[0]);
        }

        return [$start, $length];
    }

    public static function indentAt(string $code, int $pos): string
    {
        $before = substr($code, 0, $pos);
        $newline = strrpos($before, "\n");
        $line = false === $newline ? $before : substr($before, $newline + 1);

        return '' === trim($line) ? $line : '';
    }
}