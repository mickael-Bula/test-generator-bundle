<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final class ProblemSignatures
{
    /**
     * @return array<string, true> « test :: message » pour chaque problème du rapport PHPUnit
     */
    public static function of(string $phpUnitOutput): array
    {
        $set = [];

        // PHPStan : « - Ligne 233 : message »
        preg_match_all('/^- Ligne \d+ : (?<msg>.+)$/m', $phpUnitOutput, $s, PREG_SET_ORDER);
        foreach ($s as $e) {
            $set['phpstan :: ' . self::normalize($e['msg'])] = true;
        }

        // Avertissements : « 1) /chemin/Fichier.php:654 » puis le message
        preg_match_all('/^\d+\)\s+\S+\.php:\d+\R(?<msg>[^\r\n]+)/m', $phpUnitOutput, $w, PREG_SET_ORDER);
        foreach ($w as $e) {
            $set['(avertissement) :: ' . self::normalize($e['msg'])] = true;
        }

        return $set;
    }

    /** Un rapport sans ligne de bilan vient d'une erreur de syntaxe ou d'un fatal : il ne prouve rien. */
    public static function isComplete(string $output): bool
    {
        return 1 === preg_match('/^(?:Tests: \d+|OK\b)/m', $output);
    }

    /**
     * Progrès : au moins un problème a disparu, et aucun test qui n'en avait pas n'en a un.
     *
     * @param array<string, true> $before
     * @param array<string, true> $after
     */
    public static function improves(array $before, array $after): bool
    {
        $known = [];
        foreach (array_keys($before) as $signature) {
            $known[strstr($signature, ' :: ', true) ?: $signature] = true;
        }
        foreach (array_keys($after) as $signature) {
            $test = strstr($signature, ' :: ', true) ?: $signature;
            if (!\in_array($test, ['(avertissement)', 'phpstan'], true) && !isset($known[$test])) {
                return false; // régression : un test sain jusque-là échoue
            }
        }

        return [] !== array_diff_key($before, $after);
    }

    private static function normalize(string $message): string
    {
        // Numéros de ligne et chemins changent à chaque correction : seule la nature de l'erreur compte
        return trim((string) preg_replace('~%PROJECT_DIR%\S*|/\S+\.php(?::\d+)?|\bline \d+|\(lignes? [\d, ]+\)|:\d+\b~', '', $message));
    }
}