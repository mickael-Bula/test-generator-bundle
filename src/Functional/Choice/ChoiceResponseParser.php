<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Choice;

use Mika\TestGeneratorBundle\Functional\Exception\ChoiceException;

/**
 * Lit la réponse du LLM au choix d'un élément.
 *
 * Tolérante : le client LLM du bundle est conçu pour renvoyer du code de test et peut avoir enrobé
 * la réponse (bloc Markdown, ouverture PHP). On cherche donc « choice » ou « choix » où qu'il soit.
 */
final class ChoiceResponseParser
{
    /**
     * @return int|null indice (à partir de 0) du candidat choisi, ou null si le LLM n'en retient aucun
     *
     * @throws ChoiceException réponse illisible ou numéro hors de la liste
     */
    public function parse(string $response, int $candidateCount): ?int
    {
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$|^<\?php\s*/i', '', trim($response)) ?? $response);

        if (1 === preg_match('/["\']?(?:choice|choix)["\']?\s*[:=]\s*["\']?(null|none|aucun|\d+)/i', $text, $m)) {
            return $this->toIndex($m[1], $candidateCount, $response);
        }

        if (1 === preg_match('/^(null|none|aucun|\d+)\.?$/i', $text, $m)) {
            return $this->toIndex($m[1], $candidateCount, $response);
        }

        throw new ChoiceException(sprintf('Réponse du LLM inexploitable (attendu {"choice": N}) : « %s »', $this->excerpt($response)));
    }

    private function toIndex(string $value, int $candidateCount, string $response): ?int
    {
        if (!ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;
        if (0 === $number) {
            return null;
        }

        if ($number > $candidateCount) {
            throw new ChoiceException(sprintf('Le LLM a choisi le candidat %d alors qu\'il y en a %d : « %s »', $number, $candidateCount, $this->excerpt($response)));
        }

        return $number - 1;
    }

    private function excerpt(string $response): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $response));

        return mb_strlen($flat) > 200 ? mb_substr($flat, 0, 199) . '…' : $flat;
    }
}
