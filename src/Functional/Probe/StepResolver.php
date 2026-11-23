<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Probe;

use Mika\TestGeneratorBundle\Functional\Dto\Candidate;
use Mika\TestGeneratorBundle\Functional\Dto\Resolution;
use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Text\TextNormalizer;

/**
 * Retrouve, dans un instantané du DOM, l'élément désigné par le libellé d'une étape.
 *
 * Résolution par règles uniquement. Un libellé qui correspond à plusieurs éléments à égalité,
 * ou à aucun, est renvoyé comme Ambiguous ou NotFound avec ses candidats : c'est le choix
 * (LLM ou humain) qui tranche, jamais une approximation silencieuse.
 */
final class StepResolver
{
    /** Score minimal d'une correspondance retenue. */
    private const THRESHOLD = 50;

    /** Écart minimal avec le deuxième candidat pour résoudre sans ambiguïté. */
    private const GAP = 15;

    private const MAX_CANDIDATES = 8;

    /** Une cible plus courte est ignorée pour les correspondances « contient » (trop de bruit). */
    private const MIN_CONTAINS_LENGTH = 3;

    public function resolve(Step $step, ScanResult $scan): Resolution
    {
        $target = (string) $step->target;
        $key = TextNormalizer::key($target);

        $scored = [];
        foreach ($scan->elements as $position => $element) {
            if (!$this->isCompatible($step->action, $element)) {
                continue;
            }

            [$score, $reason] = $this->score($step, $element, $target, $key);
            if ($score > 0) {
                $scored[] = ['candidate' => new Candidate($element, $score, $reason), 'position' => $position];
            }
        }

        $candidates = $this->sorted($scored);
        $enabled = array_values(array_filter($candidates, static fn (Candidate $c): bool => !$c->element->disabled));
        $strong = array_values(array_filter($enabled, static fn (Candidate $c): bool => $c->score >= self::THRESHOLD));

        if ([] !== $strong) {
            if (1 === count($strong) || $strong[0]->score - $strong[1]->score >= self::GAP) {
                return new Resolution(ResolutionStatus::Resolved, $strong[0]->element, [$strong[0]]);
            }

            return new Resolution(ResolutionStatus::Ambiguous, null, array_slice($strong, 0, self::MAX_CANDIDATES));
        }

        // Rien d'actif ne correspond : on signale d'abord un éventuel élément désactivé qui correspondrait.
        $disabledMatches = array_values(array_filter(
            $candidates,
            static fn (Candidate $c): bool => $c->element->disabled && $c->score >= self::THRESHOLD,
        ));
        if ([] !== $disabledMatches) {
            return new Resolution(
                ResolutionStatus::NotFound,
                null,
                array_map(static fn (Candidate $c): Candidate => new Candidate($c->element, $c->score, 'désactivé : ' . $c->reason), array_slice($disabledMatches, 0, self::MAX_CANDIDATES)),
            );
        }

        $nearest = $this->nearest($step, $scan, $key);

        return new Resolution(ResolutionStatus::NotFound, null, [] !== $nearest ? $nearest : $this->anyCompatible($step, $scan));
    }

    /**
     * Valeur de l'option d'une liste correspondant au texte demandé (texte exact, puis valeur, puis texte partiel unique).
     */
    public function resolveOption(ScannedElement $select, string $wanted): ?string
    {
        $key = TextNormalizer::key($wanted);

        foreach ($select->options as $option) {
            if (TextNormalizer::key($option['text']) === $key) {
                return $option['value'];
            }
        }

        foreach ($select->options as $option) {
            if ($option['value'] === $wanted) {
                return $option['value'];
            }
        }

        $partial = [];
        if (mb_strlen($key) >= self::MIN_CONTAINS_LENGTH) {
            foreach ($select->options as $option) {
                if (str_contains(TextNormalizer::key($option['text']), $key)) {
                    $partial[] = $option['value'];
                }
            }
        }

        return 1 === count($partial) ? $partial[0] : null;
    }

    private function isCompatible(StepAction $action, ScannedElement $element): bool
    {
        return match ($action) {
            StepAction::Click => in_array($element->kind(), ['link', 'button', 'other'], true),
            StepAction::Fill => 'textbox' === $element->kind(),
            StepAction::Select => 'select' === $element->kind(),
            StepAction::Check, StepAction::Uncheck => in_array($element->kind(), ['checkbox', 'radio'], true),
            StepAction::Visit => false,
        };
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function score(Step $step, ScannedElement $element, string $target, string $key): array
    {
        $best = 0;
        $reason = '';

        foreach ($this->signals($step->action, $element) as [$text, $exact, $partial, $label]) {
            if (null === $text || '' === $text) {
                continue;
            }

            $textKey = TextNormalizer::key($text);
            if ('' === $textKey) {
                continue;
            }

            if ($textKey === $key && $exact > $best) {
                $best = $exact;
                $reason = $label . ' exact';
            } elseif ($partial > 0 && mb_strlen($key) >= self::MIN_CONTAINS_LENGTH && str_contains($textKey, $key) && $partial > $best) {
                $best = $partial;
                $reason = $label . ' contient le libellé';
            }
        }

        // Nom technique écrit tel quel par l'auteur (id ou name) : position[buyPrice], isActive…
        foreach ([['id', $element->id], ['name', $element->name]] as [$label, $value]) {
            if (null !== $value && 0 === strcasecmp($value, $target) && 70 > $best) {
                $best = 70;
                $reason = $label . ' identique au libellé';
            }
        }

        if ($best > 0) {
            $best += $this->nounAdjustment($step->noun, $element);
        }

        return [max(0, $best), $reason];
    }

    /**
     * Sources de libellé de l'élément, par ordre de fiabilité : [texte, score exact, score « contient », nom de la source].
     *
     * @return list<array{0: ?string, 1: int, 2: int, 3: string}>
     */
    private function signals(StepAction $action, ScannedElement $element): array
    {
        if (StepAction::Click === $action) {
            return [
                [$element->testId, 100, 0, 'data-testid'],
                [$element->ariaLabel, 95, 65, 'aria-label'],
                [$element->text, 90, 60, 'texte'],
                [$element->label, 90, 60, 'label'],
                [$element->targetTitle, 85, 55, 'titre du panneau ouvert par ce bouton'],
                [$element->title, 80, 55, 'title'],
            ];
        }

        if (in_array($action, [StepAction::Check, StepAction::Uncheck], true)) {
            return [
                [$element->testId, 100, 0, 'data-testid'],
                [$element->label, 100, 65, 'label'],
                [$element->ariaLabel, 95, 65, 'aria-label'],
                [$element->following, 90, 60, 'texte qui suit la case'],
                [$element->nearby, 60, 0, 'texte voisin'],
                [$element->title, 70, 50, 'title'],
            ];
        }

        return [
            [$element->testId, 100, 0, 'data-testid'],
            [$element->label, 100, 65, 'label'],
            [$element->ariaLabel, 95, 65, 'aria-label'],
            [$element->nearby, 85, 55, 'texte voisin'],
            [$element->placeholder, 75, 50, 'placeholder'],
            [$element->title, 70, 50, 'title'],
        ];
    }

    private function nounAdjustment(?string $noun, ScannedElement $element): int
    {
        return match (true) {
            'bouton' === $noun && 'button' === $element->kind() => 5,
            'lien' === $noun && 'link' === $element->kind() => 5,
            'lien' === $noun && 'button' === $element->kind() => -20,
            'onglet' === $noun && 'tab' === strtolower((string) $element->role) => 5,
            default => 0,
        };
    }

    /**
     * @param list<array{candidate: Candidate, position: int}> $scored
     *
     * @return list<Candidate>
     */
    private function sorted(array $scored): array
    {
        usort($scored, static function (array $a, array $b): int {
            return $b['candidate']->score <=> $a['candidate']->score ?: $a['position'] <=> $b['position'];
        });

        return array_map(static fn (array $row): Candidate => $row['candidate'], $scored);
    }

    /**
     * Dernier recours pour le choix : quelques éléments compatibles, dans l'ordre de la page (cas des icônes sans libellé).
     *
     * @return list<Candidate>
     */
    private function anyCompatible(Step $step, ScanResult $scan): array
    {
        $found = [];
        foreach ($scan->elements as $element) {
            if ($this->isCompatible($step->action, $element) && !$element->disabled) {
                $found[] = new Candidate($element, 1, 'élément compatible (aucun libellé proche)');
            }

            if (count($found) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        return $found;
    }

    /**
     * Éléments compatibles les plus proches par distance de texte : proposés comme candidats, jamais résolus seuls.
     *
     * @return list<Candidate>
     */
    private function nearest(Step $step, ScanResult $scan, string $key): array
    {
        $found = [];
        foreach ($scan->elements as $position => $element) {
            if (!$this->isCompatible($step->action, $element) || $element->disabled) {
                continue;
            }

            $distance = null;
            foreach ($this->signals($step->action, $element) as [$text]) {
                if (null === $text || '' === TextNormalizer::key($text)) {
                    continue;
                }

                $d = TextNormalizer::distance($key, $text);
                $distance = null === $distance ? $d : min($distance, $d);
            }

            if (null !== $distance && $distance <= 0.5) {
                $found[] = [
                    'candidate' => new Candidate($element, (int) round(45 * (1 - $distance)), sprintf('approchant (distance %.2f)', $distance)),
                    'position' => $position,
                ];
            }
        }

        return array_slice($this->sorted($found), 0, self::MAX_CANDIDATES);
    }
}
