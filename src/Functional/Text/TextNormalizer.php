<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Text;

/**
 * Normalisation des libellés pour les comparer : casse, accents, espaces, glyphes d'icônes.
 */
final class TextNormalizer
{
    /** Plages « usage privé » (polices d'icônes) : BMP et plans supplémentaires. */
    private const PRIVATE_USE = '/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u';

    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ];

    /**
     * Forme comparable : minuscules, sans accents, sans glyphe d'icône, espaces réduits.
     */
    public static function fold(string $text): string
    {
        $text = (string) preg_replace(self::PRIVATE_USE, ' ', $text);
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{00A0}", "\u{202F}"], ["'", "'", ' ', ' '], $text);
        $text = mb_strtolower($text);

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (false !== $decomposed) {
                $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        $text = strtr($text, self::ACCENTS);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Forme de comparaison d'un libellé : comme fold(), sans ponctuation finale décorative (« : », « * », « … »).
     */
    public static function key(string $text): string
    {
        $folded = self::fold($text);

        return rtrim($folded, " :*\u{2026}.");
    }

    /**
     * Texte visible sans glyphe d'icône, espaces réduits, casse conservée.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace(self::PRIVATE_USE, ' ', $text);
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Distance de Levenshtein normalisée entre deux formes comparables (0 = identique, 1 = sans rapport).
     */
    public static function distance(string $a, string $b): float
    {
        $a = self::key($a);
        $b = self::key($b);
        // levenshtein() travaille sur les octets et limite ses entrées à 255 : on tronque à 200.
        $a = substr($a, 0, 200);
        $b = substr($b, 0, 200);
        $max = max(strlen($a), strlen($b));
        if (0 === $max) {
            return 0.0;
        }

        return min(1.0, levenshtein($a, $b) / $max);
    }
}
