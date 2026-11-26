<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Generation;

use Mika\TestGeneratorBundle\Functional\Dto\GenerationReport;
use Mika\TestGeneratorBundle\Functional\Enum\GenerationStatus;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Text\ElementDescriber;

/**
 * Compte rendu d'une génération en lignes de texte brut.
 */
final class GenerationReportFormatter
{
    /**
     * @return list<string>
     */
    public function lines(GenerationReport $report): array
    {
        $lines = [];
        if ('' !== $report->title) {
            $lines[] = sprintf('Scénario : %s', $report->title);
        }

        if ([] !== $report->steps) {
            $lines[] = 'Étapes résolues :';
            foreach ($report->steps as $step) {
                $target = null === $step->selector ? '' : '  ' . $this->target($step->container, $step->selector);
                $lines[] = sprintf('  %d. %s  [%s]%s%s', $step->index, $step->raw, $step->method->label(), $step->fragile ? ' (fragile)' : '', $target);
            }
        }

        if (null !== $report->pending && null !== $report->pendingStep) {
            $needsDecision = GenerationStatus::NeedsDecision === $report->status;
            $lines[] = sprintf(
                '%s à l\'étape %d : %s (%s)',
                $needsDecision ? 'Décision requise' : 'Étape non résolue par les règles',
                $report->pendingStep->index,
                $report->pendingStep->raw,
                ResolutionStatus::Ambiguous === $report->pending->status ? 'plusieurs éléments correspondent' : 'aucun élément ne correspond',
            );
            if (null !== $report->pending->path) {
                $lines[] = sprintf('Page courante : %s', $report->pending->path);
            }
            $lines[] = 'Candidats :';
            foreach ($report->pending->candidates as $position => $candidate) {
                $lines[] = sprintf('  %d. %s', $position + 1, ElementDescriber::describe($candidate));
            }
            if ($needsDecision) {
                $lines[] = 'Précisez le libellé de l\'étape dans le scénario, ou relancez sans --no-llm pour que le LLM tranche.';
            }
        }

        foreach ($report->warnings as $warning) {
            $lines[] = 'Avertissement : ' . $warning;
        }

        if (null !== $report->error) {
            $lines[] = 'Échec : ' . $report->error;
        }

        if ($report->probeRuns > 0) {
            $lines[] = sprintf('Exécutions de la sonde : %d', $report->probeRuns);
        }

        return $lines;
    }

    /**
     * Le sélecteur d'un élément du panneau contient parfois déjà celui du panneau : on ne le répète pas.
     */
    private function target(?string $container, string $selector): string
    {
        return null === $container || str_starts_with($selector, $container) ? $selector : $container . ' ' . $selector;
    }
}
