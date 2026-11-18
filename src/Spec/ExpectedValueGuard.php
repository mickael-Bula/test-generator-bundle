<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Spec;

/**
 * Un `Retour attendu` numérique que ni les entrées ni les retours des mocks ne permettent de retrouver est un résultat
 * calculé de tête par le LLM de la spec : le générateur le recopie et le test échoue. On le remplace par une consigne.
 */
final class ExpectedValueGuard
{
    public const NOTICE = '* **Retour attendu** : à déduire du code de la classe testée. Les valeurs de la spec ne sont pas fiables pour ce cas '
    . '(elles reposaient sur des retours de la classe testée ou sur des données absentes des entrées) : '
    . 'écris l\'attendu comme une EXPRESSION des entrées du test, PHP fait le calcul.';

    /**
     * @param string $block    un cas de test (`#### …`)
     * @param bool   $orphaned vrai si une attente de mock sur la classe testée vient d'être retirée de ce cas
     */
    public function apply(string $block, bool $orphaned): string
    {
        if (1 !== preg_match('/^\* \*\*Retour attendu\*\* : `(?<value>.*)`[ \t]*$/m', $block, $m)) {
            return $block;
        }

        $expected = array_filter(
            $this->numbers(json_decode($m['value'], true)),
            static fn (float $n): bool => 0.0 !== $n,
        );
        if ([] === $expected) {
            return $block; // rien de numérique à justifier : chaînes, booléens, zéros, tableaux vides
        }

        $sources = $this->sources($block);
        $hasSource = [] !== array_filter($sources, static fn (float $n): bool => 0.0 !== $n);
        if (!$orphaned && $hasSource) {
            return $block;
        }

        if ([] === array_filter($expected, static fn (float $n): bool => !\in_array($n, $sources, true))) {
            return $block; // chaque nombre attendu figure déjà dans les entrées ou les mocks
        }

        return (string) preg_replace_callback(
            '/^\* \*\*Retour attendu\*\* : `.*`[ \t]*$/m',
            static fn (): string => self::NOTICE,
            $block,
            1,
        );
    }

    /**
     * @return list<float>
     */
    private function numbers(mixed $value): array
    {
        if (\is_int($value) || \is_float($value)) {
            return [(float) $value];
        }
        if (!\is_array($value)) {
            return [];
        }

        $numbers = [];
        foreach ($value as $item) {
            array_push($numbers, ...$this->numbers($item));
        }

        return $numbers;
    }

    /**
     * Nombres que le test pose lui-même : la ligne « Entrées » et les lignes de retour des mocks.
     *
     * @return list<float>
     */
    private function sources(string $block): array
    {
        $numbers = [];
        foreach (explode("\n", $block) as $line) {
            if (1 !== preg_match('/^(?:\* \*\*Entrées\*\*| {2}- )/', $line)) {
                continue;
            }
            preg_match_all('/-?\d+(?:\.\d+)?/', $line, $found);
            foreach ($found[0] as $number) {
                $numbers[] = (float) $number;
            }
        }

        return $numbers;
    }
}
