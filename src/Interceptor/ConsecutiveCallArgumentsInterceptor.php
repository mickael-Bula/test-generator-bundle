<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/**
 * Intercepteur sur une même méthode qui est mockée plusieurs fois avec des arguments différents.
 * Signale une incapacité du LLM à trouver la bonne méthode de mock.
 * Il se déclenche seulement sur « invoked N times » avec N ≥ 2, suivi d’un `Parameter … does not match`
 * Il ne chevauche donc pas les cas du nombre d’appels (avec un point après « times ») ni du with() erroné sur un seul appel.
 *
 * @noinspection PhpUnused
 */
final class ConsecutiveCallArgumentsInterceptor implements ErrorInterceptorInterface
{
    // « Expectation failed for method name is "findOneBy" when invoked 2 times
    //   Parameter 1 for invocation Doctrine\…\ServiceEntityRepositoryProxy::findOneBy([...], [...]): object does not match expected value. »
    // N >= 2 seulement : « invoked 1 time » est un simple with() erroné, et « times. » (avec un point) un nombre d'appels incorrect.
    // language=RegExp
    private const PATTERN = '/Expectation failed for method name is "(?<method>\w+)" when invoked (?<times>[2-9]|\d{2,}) times\R+Parameter (?<index>\d+) for invocation [^\n]*?::\k<method>\(/';
    private const MAX_PLACEHOLDERS = 6;

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match_all(self::PATTERN, $errorOutput, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $md = "### Même méthode mockée appelée plusieurs fois avec des arguments différents (relevé par PHPUnit)\n";
        $seen = [];
        foreach ($matches as $match) {
            $method = $match['method'][0];
            if (isset($seen[$method])) {
                continue;
            }
            $seen[$method] = true;

            $times = (int) $match['times'][0];
            $argument = (int) $match['index'][0] + 1;
            $block = $this->failureBlock($errorOutput, $match[0][1]);
            [$expected, $actual] = $this->difference($block);
            $statement = $this->callerStatement($block);

            $md .= sprintf(
                "\n- `%s` est attendue %d fois (`self::exactly(%d)`) et l'argument n°%d reçu par l'un des appels ne correspond pas à ce que `->with(...)` impose%s.\n",
                $method,
                $times,
                $times,
                $argument,
                null !== $expected && null !== $actual ? sprintf(' : attendu `%s`, reçu `%s`', $expected, $actual) : '',
            );
            $md .= sprintf(
                "- Un seul `->with(...)` impose les MÊMES arguments à TOUS les appels : si le code testé appelle `%s` plusieurs fois avec des valeurs différentes, "
                . "alterner la valeur dans `with()` d'une tentative à l'autre ne peut pas aboutir, l'un des appels échouera toujours.\n",
                $method,
            );
            $md .= "- `withConsecutive()` et `at()` n'existent plus depuis PHPUnit 10 : ne les utilise pas.\n";
            $md .= null !== $statement
                ? sprintf("- Appel en échec dans le code testé : %s. Lis le code pour relever, dans l'ordre d'exécution, les arguments réels de chaque appel.\n", $statement)
                : sprintf("- Lis le code testé pour relever, dans l'ordre d'exécution, les arguments réels de chaque appel de `%s`.\n", $method);
            $md .= "- Correction : enregistre les arguments reçus, puis vérifie-les dans la partie ALORS :\n\n";

            $count = min($times, self::MAX_PLACEHOLDERS);
            $returns = $arguments = [];
            for ($i = 1; $i <= $count; ++$i) {
                $returns[] = sprintf("<valeur renvoyée par l'appel %d>", $i);
                $arguments[] = sprintf("[<arguments de l'appel %d>]", $i);
            }

            $code = [
                '$received = [];',
                sprintf('$returns = [%s];', implode(', ', $returns)),
                sprintf('$mock->expects(self::exactly(%d))->method(\'%s\')->willReturnCallback(', $times, $method),
                '    static function (mixed ...$arguments) use (&$received, &$returns): mixed {',
                '        $received[] = $arguments;',
                '',
                '        return array_shift($returns);',
                '    },',
                ');',
                '',
                sprintf('self::assertEquals([%s], $received);', implode(', ', $arguments)),
            ];
            foreach ($code as $line) {
                $md .= '' === $line ? "\n" : '      ' . $line . "\n";
            }
        }

        return $md . "\nNe modifie ni le nombre d'appels attendus ni le reste du test : "
            . "corrige seulement la façon dont les arguments de ces appels sont vérifiés.\n";
    }

    /**
     * Le bloc du rapport qui concerne cet échec : de la ligne trouvée au prochain « N) » ou « -- ».
     */
    private function failureBlock(string $output, int $offset): string
    {
        $rest = substr($output, $offset);
        $end = 1 === preg_match('/\n(?:\d+\) |--\n)/', $rest, $boundary, PREG_OFFSET_CAPTURE, 1) ? $boundary[0][1] : \strlen($rest);

        return substr($rest, 0, $end);
    }

    /**
     * @return array{0: ?string, 1: ?string} première ligne retirée (attendu) et première ligne ajoutée (reçu) du diff PHPUnit
     */
    private function difference(string $block): array
    {
        $position = strpos($block, '+++ Actual');
        if (false === $position) {
            return [null, null];
        }

        $expected = $actual = null;
        foreach (explode("\n", substr($block, $position)) as $line) {
            if (null === $expected && 1 === preg_match('/^-(?!-)\s*(.+)$/', $line, $m)) {
                $expected = trim($m[1]);
            } elseif (null === $actual && 1 === preg_match('/^\+(?!\+)\s*(.+)$/', $line, $m)) {
                $actual = trim($m[1]);
            }
        }

        return [$expected, $actual];
    }

    /**
     * L'instruction du code testé (hors tests et vendor) citée par la pile du rapport, si le fichier est lisible.
     */
    private function callerStatement(string $block): ?string
    {
        foreach (explode("\n", $block) as $line) {
            if (1 !== preg_match('/^(?<file>\S+\.php):(?<line>\d+)$/', trim($line), $m)) {
                continue;
            }
            if (1 === preg_match('#[\\\\/](?:tests|vendor)[\\\\/]#', $m['file']) || !is_readable($m['file'])) {
                continue;
            }

            $lines = file($m['file'], FILE_IGNORE_NEW_LINES);
            $start = (int) $m['line'] - 1;
            if (false === $lines || !isset($lines[$start])) {
                continue;
            }

            $statement = [];
            for ($i = $start; $i < \count($lines) && \count($statement) < 6; ++$i) {
                $statement[] = trim($lines[$i]);
                if (str_contains($lines[$i], ';')) {
                    break;
                }
            }

            return sprintf('ligne %d de `%s` : `%s`', (int) $m['line'], basename($m['file']), implode(' ', $statement));
        }

        return null;
    }
}
