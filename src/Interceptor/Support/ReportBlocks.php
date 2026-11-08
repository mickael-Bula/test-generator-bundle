<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final class ReportBlocks
{
    private const SECTION = '/^(?:There (?:was|were) \d+ .+:|ERREUR DE QUALITÉ .+:|\d+ tests? triggered .+:)$/';
    private const END = '/^(?:FAILURES!|ERRORS!|WARNINGS!|OK\b|Tests: \d+)/';
    private const SKIPPED = '/^There (?:was|were) \d+ (?:skipped|incomplete) tests?:/';

    /**
     * Un bloc par problème, en-tête de section compris (« There was 1 failure: »).
     *
     * @return array<string, string> signature => bloc de rapport
     */
    public static function of(string $output): array
    {
        $output = str_replace(["\r\n", "\r"], "\n", $output);

        $blocks = [];
        $header = '';
        $chunk = [];
        $emit = static function () use (&$chunk, &$header, &$blocks): void {
            if ([] === $chunk) {
                return;
            }
            $text = rtrim(implode("\n", $chunk));
            $signature = array_key_first(ProblemSignatures::of($text));
            if (null !== $signature) {
                $blocks[$signature] = ('' !== $header ? $header . "\n\n" : '') . $text;
            }
            $chunk = [];
        };

        foreach (explode("\n", $output) as $line) {
            if (1 === preg_match(self::SECTION, $line)) {
                $emit();
                $header = $line;
            } elseif (1 === preg_match('/^\d+\) /', $line)) {
                $emit();
                $chunk = [$line];
            } elseif (1 === preg_match('/^- Ligne \d+ : /', $line)) { // PHPStan : une ligne = un problème
                $emit();
                $chunk = [$line];
                $emit();
            } elseif ('--' === $line || 1 === preg_match(self::END, $line)) {
                $emit();
                $header = '';
            } elseif ([] !== $chunk) {
                $chunk[] = $line;
            }
        }
        $emit();

        return $blocks;
    }

    /**
     * Les mêmes blocs, regroupés par test. Les problèmes qui ne désignent aucun test (PHPStan, erreur fatale,
     * violation « classLevel ») gardent leur propre clé (« phpstan », « classLevel »...) : ce ne sont pas des méthodes.
     *
     * @return array<string, string> nom du test => bloc(s) de rapport
     */
    public static function byTest(string $output, bool $includeSkipped = true): array
    {
        $byTest = [];
        foreach (self::of($output) as $signature => $block) {
            // Un test ignoré ou incomplet n'est pas un échec : on écarte son bloc, mais pas les autres blocs du même test (qualité, avertissement).
            if (!$includeSkipped && 1 === preg_match(self::SKIPPED, $block)) {
                continue;
            }

            $test = strstr($signature, ' :: ', true) ?: $signature;

            // Un avertissement PHP est rattaché aux tests qui l'ont déclenché (« Triggered by: * Classe::test »)
            $tests = '(avertissement)' === $test && preg_match_all('/^\* [\w\\\\]+::(\w+)/m', $block, $m) > 0
                ? array_values(array_unique($m[1]))
                : [$test];

            foreach ($tests as $name) {
                $byTest[$name] = isset($byTest[$name]) ? $byTest[$name] . "\n\n" . $block : $block;
            }
        }

        return $byTest;
    }
}
