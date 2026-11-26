<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Generation;

use Mika\TestGeneratorBundle\Functional\Choice\NullStepChooser;
use Mika\TestGeneratorBundle\Functional\Choice\StepChooserInterface;
use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\GenerationReport;
use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Dto\StepReport;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionMethod;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use Mika\TestGeneratorBundle\Functional\Probe\ProbeRunnerInterface;
use Mika\TestGeneratorBundle\Functional\Render\FunctionalTestRenderer;
use Mika\TestGeneratorBundle\Functional\Render\RenderOptions;

/**
 * Du scénario Markdown au code du test : sonde, décisions pour les étapes ambiguës, rendu.
 *
 * La sonde rejoue le scénario dans un navigateur. Quand elle bute sur une étape que les règles
 * n'ont pas résolue, le choix (LLM) désigne un candidat et la sonde est relancée avec ce choix :
 * elle l'exécute, donc le valide. Chaque relance repart de la base de test restaurée.
 */
final class FunctionalTestGenerator
{
    public function __construct(
        private readonly ProbeRunnerInterface $runner,
        private readonly StepChooserInterface $chooser = new NullStepChooser(),
        private readonly FunctionalTestRenderer $renderer = new FunctionalTestRenderer(),
        private readonly FunctionalScenarioParser $parser = new FunctionalScenarioParser(),
        private readonly int $maxDecisions = 5,
    ) {
    }

    /**
     * @param StepChooserInterface|null $chooser      remplace le choix configuré (ex. NullStepChooser pour --no-llm)
     * @param int|null                  $maxDecisions nombre maximal d'étapes tranchées par le choix, avant abandon
     */
    public function generate(string $scenarioPath, RenderOptions $options, ?StepChooserInterface $chooser = null, ?int $maxDecisions = null): GenerationReport
    {
        $chooser ??= $this->chooser;
        $maxDecisions ??= $this->maxDecisions;

        try {
            $scenario = $this->parser->parseFile($scenarioPath);
        } catch (\Throwable $e) {
            return GenerationReport::failed('', '', [], $e->getMessage(), 0);
        }

        $title = $scenario->title;
        $className = $this->renderer->className($scenario, $options);
        $decisions = new ScenarioDecisions();
        $byChoice = [];
        $runs = 0;

        while (true) {
            try {
                $probe = $this->runner->run($scenarioPath, $decisions);
            } catch (\Throwable $e) {
                return GenerationReport::failed($title, $className, [], $e->getMessage(), $runs);
            }

            ++$runs;
            $steps = $this->stepReports($scenario, $probe, $byChoice);

            if (ProbeStatus::Ok === $probe->status) {
                return $this->render($scenario, $probe, $options, $className, $steps, $runs);
            }

            if (ProbeStatus::Failed === $probe->status) {
                return GenerationReport::failed($title, $className, $steps, $probe->error ?? 'La sonde a échoué sans détail.', $runs, $probe->warnings);
            }

            $pending = $probe->pending;
            $step = null === $pending ? null : $this->stepAt($scenario, $pending->stepIndex);
            if (null === $pending || null === $step) {
                return GenerationReport::failed($title, $className, $steps, 'La sonde demande une décision sans la décrire.', $runs);
            }

            if (isset($byChoice[$pending->stepIndex])) {
                return GenerationReport::failed($title, $className, $steps, sprintf('Étape %d : la sonde redemande une décision malgré le choix appliqué.', $step->index), $runs);
            }

            if ([] === $pending->candidates) {
                return GenerationReport::failed($title, $className, $steps, sprintf('Étape %d (« %s ») : aucun élément compatible dans la page.', $step->index, $step->raw), $runs);
            }

            if (count($byChoice) >= $maxDecisions) {
                return GenerationReport::failed($title, $className, $steps, sprintf('Étape %d : plus de %d décisions nécessaires, abandon (précisez les libellés du scénario).', $step->index, $maxDecisions), $runs);
            }

            try {
                $element = $chooser->choose($scenario, $step, $pending);
            } catch (\Throwable $e) {
                return GenerationReport::failed($title, $className, $steps, sprintf('Étape %d : choix impossible : %s', $step->index, $e->getMessage()), $runs, [], $step, $pending);
            }

            if (null === $element) {
                return GenerationReport::needsDecision($title, $className, $steps, $step, $pending, $runs);
            }

            $decisions = $decisions->with($step->index, $element);
            $byChoice[$step->index] = true;
        }
    }

    /**
     * @param list<StepReport> $steps
     */
    private function render(FunctionalScenario $scenario, ProbeResult $probe, RenderOptions $options, string $className, array $steps, int $runs): GenerationReport
    {
        try {
            $source = $this->renderer->render($scenario, $probe, $options);
        } catch (\Throwable $e) {
            return GenerationReport::failed($scenario->title, $className, $steps, $e->getMessage(), $runs, $probe->warnings);
        }

        $warnings = $probe->warnings;
        foreach ($steps as $step) {
            if ($step->fragile) {
                $warnings[] = sprintf('Étape %d : sélecteur fragile (chemin dans le DOM, il cassera si la page change) : %s', $step->index, $step->selector);
            }
        }

        return GenerationReport::generated($scenario->title, $className, $source, $steps, $warnings, $runs);
    }

    /**
     * @param array<int, true> $chosen étapes tranchées par le choix
     *
     * @return list<StepReport>
     */
    private function stepReports(FunctionalScenario $scenario, ProbeResult $probe, array $chosen): array
    {
        $reports = [];
        foreach ($probe->steps as $resolved) {
            $step = $this->stepAt($scenario, $resolved->stepIndex);
            if (null === $step) {
                continue;
            }

            $method = match (true) {
                StepAction::Visit === $step->action => ResolutionMethod::Navigation,
                isset($chosen[$step->index]) => ResolutionMethod::Llm,
                default => ResolutionMethod::Rule,
            };

            $reports[] = new StepReport(
                $step->index,
                $step->raw,
                $method,
                $resolved->target?->selector,
                $resolved->target?->container,
                $resolved->target?->fragile ?? false,
            );
        }

        return $reports;
    }

    private function stepAt(FunctionalScenario $scenario, int $index): ?Step
    {
        foreach ($scenario->steps as $step) {
            if ($step->index === $index) {
                return $step;
            }
        }

        return null;
    }
}
