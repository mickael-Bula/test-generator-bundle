<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Spec;

final class JsonRepair
{
    private const SCALAR = '/^(?:-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)$/';

    /**
     * Décode le JSON d'un LLM. Si le décodage échoue, tente de réparer : valeur sans guillemets
     * (`"returnValue": 2 / 100,`), puis virgule en trop avant `}` ou `]`.
     *
     * @return array{data: mixed, fixes: list<string>}
     *
     * @throws \JsonException l'erreur du JSON d'origine si la réparation ne suffit pas
     */
    public static function decode(string $raw): array
    {
        try {
            return ['data' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR), 'fixes' => []];
        } catch (\JsonException $original) {
        }

        [$quoted, $fixes] = self::quoteBareValues($raw);
        $candidates = [
            [$quoted, $fixes],
            [self::removeTrailingCommas($quoted), [...$fixes, 'virgule en trop retirée']],
        ];

        foreach ($candidates as [$candidate, $applied]) {
            if ([] === $applied) {
                continue; // identique à l'original, déjà refusé
            }
            try {
                return ['data' => json_decode($candidate, true, 512, JSON_THROW_ON_ERROR), 'fixes' => $applied];
            } catch (\JsonException) {
            }
        }

        throw $original;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function quoteBareValues(string $json): array
    {
        $fixes = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $json));

        foreach ($lines as $i => $line) {
            // `"clé": <valeur>,` dont la valeur ne commence pas par un guillemet, un crochet ou une accolade.
            if (1 !== preg_match('/^(?<head>\s*"[^"]+"\s*:\s*)(?<value>[^"\[{\s].*?)(?<tail>,?)\s*$/', $line, $m)) {
                continue;
            }
            $value = trim($m['value']);
            if (1 === preg_match(self::SCALAR, $value)) {
                continue; // nombre, true, false ou null : valide
            }

            $lines[$i] = $m['head'] . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $m['tail'];
            $fixes[] = sprintf('ligne %d : `%s` mis entre guillemets', $i + 1, $value);
        }

        return [implode("\n", $lines), $fixes];
    }

    private static function removeTrailingCommas(string $json): string
    {
        return (string) preg_replace('/,(\s*[}\]])/', '$1', $json);
    }
}