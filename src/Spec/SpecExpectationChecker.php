<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Spec;

use Mika\TestGeneratorBundle\Reflection\ThrownMessages;
use Psr\Log\LoggerInterface;

final readonly class SpecExpectationChecker
{
    public function __construct(
        private ThrownMessages $thrown,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return string|null la section de la méthode, vérifiée ; null s'il ne reste ni cas de test ni data provider
     */
    public function check(string $chunk, \ReflectionClass $sut, \ReflectionMethod $method): ?string
    {
        $chunk = $this->checkProvider($chunk, $method);

        $parts = preg_split('/(?=^#### )/m', $chunk) ?: [$chunk];
        $head = (string) array_shift($parts);

        $sites = $this->thrown->of($sut);
        $classes = $this->thrown->classes($sut);

        $kept = [];
        foreach ($parts as $block) {
            if (null !== $checked = $this->checkCase($block, $method, $sites, $classes)) {
                $kept[] = $checked;
            }
        }

        return [] === $kept && !str_contains($head, '### Data Provider') ? null : $head . implode('', $kept);
    }

    /**
     * Lignes d'un dataProvider dont le résultat attendu est `null` pour un type de retour non nullable.
     */
    private function checkProvider(string $chunk, \ReflectionMethod $method): string
    {
        if (!$this->cannotReturnNull($method)
            || 1 !== preg_match('/^### Data Provider.*?(?=^### |\z)/ms', $chunk, $m, PREG_OFFSET_CAPTURE)) {
            return $chunk;
        }
        [$section, $position] = $m[0];

        $body = rtrim($section, "\n");
        $trailing = substr($section, \strlen($body));
        $lines = explode("\n", $body);

        $table = array_keys(array_filter($lines, static fn (string $l): bool => str_starts_with(ltrim($l), '|')));
        if (\count($table) < 3) {
            return $chunk;
        }

        $column = null;
        foreach ($this->cells($lines[$table[0]]) as $i => $cell) {
            if (1 === preg_match('/expected|attendu|r[ée]sultat|result/i', $cell)) {
                $column = $i;
            }
        }
        if (null === $column) {
            return $chunk;
        }

        $rows = \array_slice($table, 2);
        $removed = 0;
        foreach ($rows as $index) {
            if ('null' === strtolower(trim($this->cells($lines[$index])[$column] ?? '', ' `'))) {
                unset($lines[$index]);
                ++$removed;
                $this->logger?->info(sprintf(
                    'Spec : ligne de data provider écartée, `null` attendu mais %s::%s() est typé %s.',
                    $method->class, $method->name, $method->getReturnType()
                    )
                );
            }
        }
        if (0 === $removed) {
            return $chunk;
        }

        // Plus aucune ligne : on retire tout le bloc provider
        return substr_replace($chunk, $removed === \count($rows) ? '' : implode("\n", $lines) . $trailing, $position, \strlen($section));
    }

    /** @param list<array{class: string, method: string, line: int, display: string, regex: string, stable: string}> $sites */
    private function checkCase(string $block, \ReflectionMethod $method, array $sites, array $classes): ?string
    {
        $title = 1 === preg_match('/^#### `(\w+)`/', $block, $t) ? $t[1] : '?';

        // `null` attendu, type de retour non nullable : PHP lèverait une TypeError
        if (1 === preg_match('/^\* \*\*Retour attendu\*\* : `null`\s*$/m', $block) && $this->cannotReturnNull($method)) {
            $this->logger?->info(sprintf(
                'Spec : %s écarté, `null` attendu mais %s::%s() est typé %s.',
                $title, $method->class, $method->name, $method->getReturnType()
                )
            );

            return null;
        }

        // Une exception qui vient d'un mock (`➔ lève`) n'a aucun `throw` à retrouver dans le SUT
        if (1 === preg_match('/^\* \*\*Exception attendue\*\* : `(?<class>[^`]+)`\s*$/m', $block, $e)
            && !str_contains($block, '➔ lève')
        ) {
            return $this->checkException($block, $title, ltrim($e['class'], '\\'), $method, $sites, $classes);
        }

        return $block;
    }

    /**
     * @param list<array{class: string, method: string, line: int, display: string, regex: string, stable: string}> $sites
     */
    private function checkException(string $block, string $title, string $class, \ReflectionMethod $method, array $sites, array $classes): ?string
    {
        if (class_exists($class) && is_a($class, \Error::class, true)) {
            return $block; // erreur du moteur PHP (TypeError, DivisionByZeroError...) : aucun `throw` à retrouver
        }

        if ([] === array_filter($classes, fn (string $c): bool => $this->sameClass($c, $class))) {
            $this->logger?->info(sprintf(
                'Spec : %s écarté, %s ne lance aucune exception %s.',
                $title, $method->class, $class
                )
            );

            return null;
        }

        if (1 !== preg_match('/^  - Message : \*"(?<m>.*)"\*[ \t]*$/m', $block, $m)) {
            return $block; // pas de message à vérifier
        }
        $spec = $m['m'];
        $candidates = array_values(array_filter($sites, fn (array $s): bool => $this->sameClass($s['class'], $class)));

        if (null !== $site = $this->matching($spec, $candidates)) {
            // Message reconnu : on ne touche qu'aux gabarits (`<id>`, `{x}`, `%d`), remplacés par la partie fixe du vrai message
            return 1 === preg_match('/<[^>]*>|\{[^}]*\}|%\w/', $spec) ? $this->withMessage($block, $this->usable($site)) : $block;
        }

        // Message inventé : le message réel s'il est unique parmi les `throw` de cette classe (dans la méthode d'abord), sinon la classe seule
        $inMethod = array_values(array_filter($candidates, static fn (array $s): bool => $s['method'] === $method->name));
        $pool = [] !== $inMethod ? $inMethod : $candidates;
        $this->logger?->info(sprintf(
            'Spec : %s, message `%s` absent du code de %s : %s.',
            $title, $spec, $method->class, 1 === \count($pool) ? 'remplacé par le message réel' : 'message retiré'
            )
        );

        return $this->withMessage($block, 1 === \count($pool) ? $this->usable($pool[0]) : null);
    }

    /**
     * @param list<array{class: string, method: string, line: int, display: string, regex: string, stable: string}> $sites
     */
    private function matching(string $spec, array $sites): ?array
    {
        // Le message de la spec avec ses gabarits en jokers, comparé au message du `throw` où les parties dynamiques valent `…`
        $wild = '/^' . implode('.*?', array_map(
                static fn (string $p): string => preg_quote($p, '/'),
                preg_split('/<[^>]*>|\{[^}]*\}/', $spec) ?: [$spec],
            )) . '$/su';

        foreach ($sites as $site) {
            if (1 === preg_match($site['regex'], $spec)
                || 1 === preg_match($wild, $site['display'])
                || (mb_strlen($site['stable']) >= 12 && str_contains($spec, $site['stable']))) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Texte exploitable d'un `throw` : sa partie fixe la plus longue, ou son message complet s'il n'a aucune partie dynamique.
     */
    private function usable(array $site): ?string
    {
        if (mb_strlen($site['stable']) >= 12) {
            return $site['stable'];
        }

        return str_contains($site['display'], '…') ? null : $site['display'];
    }

    private function withMessage(string $block, ?string $message): string
    {
        if (null === $message) {
            return (string) preg_replace('/^  - Message : \*".*"\*[ \t]*\R?/m', '', $block, 1);
        }

        return (string) preg_replace_callback(
            '/^  - Message : \*".*"\*/m',
            static fn (): string => '  - Message : *"' . $message . '"*',
            $block,
            1,
        );
    }

    private function sameClass(string $thrown, string $expected): bool
    {
        $short = static fn (string $c): string => strtolower(basename(str_replace('\\', '/', $c)));
        if ($short($thrown) === $short($expected)) {
            return true;
        }

        return class_exists($expected) && is_a(ltrim($thrown, '\\'), $expected, true); // `Exception` attendu, `LogicException` lancée
    }

    private function cannotReturnNull(\ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return null !== $type && !$type->allowsNull() && !\in_array(strtolower((string) $type), ['void', 'never'], true);
    }

    /**
     * @return list<string> cellules d'une ligne de tableau Markdown ; un `|` dans un code (backticks) ne sépare pas
     */
    private function cells(string $row): array
    {
        $cells = [];
        $current = '';
        $inCode = false;
        foreach (preg_split('//u', trim($row), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ('`' === $char) {
                $inCode = !$inCode;
            }
            if ('|' === $char && !$inCode) {
                $cells[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $cells[] = trim($current);

        return \array_slice($cells, 1, -1); // la ligne commence et finit par `|`
    }
}