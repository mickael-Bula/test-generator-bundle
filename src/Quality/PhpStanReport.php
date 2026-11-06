<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

final class PhpStanReport
{
    private const LINE = '/^- Ligne (\d+) : (.+)$/';

    /**
     * Rattache chaque ligne « - Ligne N : message » à la méthode de test dont la plage contient N.
     * Tout le reste (erreur hors d'une méthode de test, ligne inattendue) est rendu dans « unattributed ».
     *
     * @param array<string, array{start: int, end: int}> $ranges voir MethodPatcher::testRanges()
     *
     * @return array{tests: array<string, string>, unattributed: list<string>} nom du test => lignes du rapport
     */
    public static function byTest(string $output, array $ranges): array
    {
        $tests = [];
        $unattributed = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $output)) as $line) {
            $line = rtrim($line);
            if ('' === $line) {
                continue;
            }

            $owner = null;
            if (1 === preg_match(self::LINE, $line, $matches)) {
                $number = (int) $matches[1];
                foreach ($ranges as $name => $range) {
                    if ($number >= $range['start'] && $number <= $range['end']) {
                        $owner = $name;
                        break;
                    }
                }
            }

            if (null === $owner) {
                $unattributed[] = $line;
                continue;
            }

            $tests[$owner] = isset($tests[$owner]) ? $tests[$owner] . "\n" . $line : $line;
        }

        return ['tests' => $tests, 'unattributed' => $unattributed];
    }
}
