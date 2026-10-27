<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final class ProblemSignatures
{
    /** @return array<string, true> « test :: message » pour chaque problème du rapport */
    public static function of(string $output): array
    {
        $output = self::clean($output);
        $set = [];

        // Erreurs, échecs, tests ignorés ou risqués, violations de qualité : « 1) Classe::test » puis le message
        preg_match_all('/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\n]*\n(?<msg>[^\n]+)/m', $output, $m, PREG_SET_ORDER);
        foreach ($m as $e) {
            $set[$e['test'] . ' :: ' . self::normalize($e['msg'])] = true;
        }

        // Avertissements : « 1) /chemin/Fichier.php:654 » puis le message
        preg_match_all('/^\d+\)\s+\S+\.php:\d+\n(?<msg>[^\n]+)/m', $output, $w, PREG_SET_ORDER);
        foreach ($w as $e) {
            $set['(avertissement) :: ' . self::normalize($e['msg'])] = true;
        }

        // PHPStan : « - Ligne 233 : message »
        preg_match_all('/^- Ligne \d+ : (?<msg>.+)$/m', $output, $s, PREG_SET_ORDER);
        foreach ($s as $e) {
            $set['phpstan :: ' . self::normalize($e['msg'])] = true;
        }

        return $set;
    }

    /** Sans ligne de bilan : le rapport vient d'une erreur de syntaxe ou d'un fatal et ne prouve rien. */
    public static function isComplete(string $output): bool
    {
        return 1 === preg_match('/^(?:Tests: \d+|OK\b)/m', self::clean($output));
    }

    /** @return array{disappeared: list<string>, appeared: list<string>, regressed: list<string>} */
    public static function compare(array $before, array $after): array
    {
        $known = [];
        foreach (array_keys($before) as $signature) {
            $known[strstr($signature, ' :: ', true) ?: $signature] = true;
        }

        $regressed = [];
        foreach (array_keys($after) as $signature) {
            $test = strstr($signature, ' :: ', true) ?: $signature;
            if (!\in_array($test, ['(avertissement)', 'phpstan'], true) && !isset($known[$test])) {
                $regressed[] = $signature; // un test qui n'avait aucun problème en a un
            }
        }

        return [
            'disappeared' => array_keys(array_diff_key($before, $after)),
            'appeared' => array_keys(array_diff_key($after, $before)),
            'regressed' => $regressed,
        ];
    }

    /**
     * Progrès : un problème a disparu, ou le même problème survient plus loin (plus d'assertions exécutées).
     * Jamais de régression.
     */
    public static function improves(array $before, array $after, ?int $assertionsBefore = null, ?int $assertionsAfter = null): bool
    {
        $delta = self::compare($before, $after);
        if ([] !== $delta['regressed']) {
            return false;
        }

        return [] !== $delta['disappeared']
            || (null !== $assertionsBefore && null !== $assertionsAfter && $assertionsAfter > $assertionsBefore);
    }

    private static function normalize(string $message): string
    {
        // Numéros de ligne et chemins changent à chaque correction : seule la nature de l'erreur compte
        return trim((string) preg_replace('~%PROJECT_DIR%\S*|/\S+\.php(?::\d+)?|\bline \d+|\(lignes? [\d, ]+\)|:\d+\b~', '', $message));
    }

    /** Séquences d'échappement (couleurs, curseur) et fins de ligne Windows. */
    private static function clean(string $output): string
    {
        $output = (string) preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $output);

        return str_replace(["\r\n", "\r"], "\n", $output);
    }

    /**
     * Nombre d'assertions exécutées, lu dans le bilan PHPUnit ; null si le bilan est absent.
     */
    public static function assertions(string $output): ?int
    {
        $output = self::clean($output);
        if (1 === preg_match('/^Tests: \d+, Assertions: (?<n>\d+)/m', $output, $m)
            || 1 === preg_match('/^OK \(\d+ tests?, (?<n>\d+) assertions?\)/m', $output, $m)
        ) {
            return (int) $m['n'];
        }

        return null;
    }

    /**
     * Signature sans ce qui varie (noms de classes, chaînes, nombres) : sert à regrouper les problèmes de même nature.
     */
    public static function shape(string $signature): string
    {
        $message = substr((string) strstr($signature, ' :: '), 4);

        // Message d'exception : les apostrophes du texte cassent le repérage des guillemets
        $message = (string) preg_replace("/exception message '.*' (contains|is) '.*'/s", 'exception message “…” $1 “…”', $message);
        $message = (string) preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/', '“…”', $message);
        $message = (string) preg_replace('/\b[A-Za-z_]\w*(?:\\\\\w+)+/', 'Classe', $message);
        $message = (string) preg_replace('/-?\d+(?:\.\d+)?/', 'N', $message);

        return mb_substr($message, 0, 110);
    }
}