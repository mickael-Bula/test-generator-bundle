<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Choice;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Text\ElementDescriber;

/**
 * Messages envoyés au LLM pour choisir l'élément visé par une étape.
 */
final class ChoicePromptBuilder
{
    private const MAX_STEPS_SHOWN = 12;

    /**
     * @return list<array{role: string, content: string}>
     */
    public function messages(FunctionalScenario $scenario, Step $step, PendingDecision $pending): array
    {
        return [
            ['role' => 'system', 'content' => $this->system()],
            ['role' => 'user', 'content' => $this->user($scenario, $step, $pending)],
        ];
    }

    private function system(): string
    {
        return <<<'TXT'
Tu aides à retrouver, dans une page web, l'élément visé par une étape d'un scénario de test fonctionnel.
On te donne le scénario, l'étape à résoudre et une liste numérotée d'éléments candidats extraits du DOM de la page courante.

Règles :
- choisis l'élément qu'un utilisateur humain activerait ou remplirait pour accomplir l'étape, d'après les libellés, les textes voisins, les titres de panneau et le contexte du scénario ;
- ne choisis jamais un élément désactivé ;
- si aucun candidat ne correspond de façon raisonnable, réponds null : une absence de choix vaut mieux qu'un mauvais choix ;
- réponds uniquement par un objet JSON d'une ligne, sans texte autour, sans code PHP, sans bloc Markdown : {"choice": N} où N est le numéro du candidat, ou {"choice": null}.
TXT;
    }

    private function user(FunctionalScenario $scenario, Step $step, PendingDecision $pending): string
    {
        $problem = ResolutionStatus::Ambiguous === $pending->status
            ? 'Plusieurs éléments correspondent au libellé de façon équivalente.'
            : 'Aucun élément ne porte exactement ce libellé ; les candidats ci-dessous sont les plus proches ou, à défaut, les éléments compatibles avec l\'action.';

        $lines = [
            sprintf('Scénario : %s', $scenario->title),
            '',
            'Étapes (celle à résoudre est marquée par >>) :',
        ];

        $shown = 0;
        foreach ($scenario->steps as $other) {
            if ($shown++ >= self::MAX_STEPS_SHOWN) {
                $lines[] = '   …';
                break;
            }

            $lines[] = sprintf('%s %d. %s', $other->index === $step->index ? '>>' : '  ', $other->index, $other->raw);
        }

        $lines[] = '';
        $lines[] = sprintf('Étape à résoudre : %s', $step->raw);
        $lines[] = sprintf('Problème : %s', $problem);
        $lines[] = '';
        $lines[] = 'Candidats :';
        foreach ($pending->candidates as $position => $candidate) {
            $lines[] = sprintf('%d. %s', $position + 1, ElementDescriber::describe($candidate));
        }

        $lines[] = '';
        $lines[] = 'Réponds par {"choice": N} ou {"choice": null}.';

        return implode("\n", $lines);
    }
}
