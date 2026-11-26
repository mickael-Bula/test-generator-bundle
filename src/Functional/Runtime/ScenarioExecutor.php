<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Runtime;

use Mika\TestGeneratorBundle\Functional\Dto\Candidate;
use Mika\TestGeneratorBundle\Functional\Dto\Expectation;
use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\Panel;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ResolvedExpectation;
use Mika\TestGeneratorBundle\Functional\Dto\ResolvedStep;
use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Enum\ExpectationType;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Probe\StepResolver;
use Mika\TestGeneratorBundle\Functional\Text\TextNormalizer;

/**
 * Déroule un scénario dans un navigateur : à chaque étape, scanne la page, résout l'élément visé,
 * exécute l'action, puis observe ce qu'elle a changé (panneau ouvert ou fermé, navigation).
 *
 * Les attentes sont ensuite confrontées à l'état final. Le résultat décrit tout ce dont le
 * gabarit de génération a besoin, ou la décision qu'il manque pour continuer.
 */
final class ScenarioExecutor
{
    public function __construct(private readonly StepResolver $resolver = new StepResolver())
    {
    }

    public function execute(FunctionalScenario $scenario, BrowserPort $browser, ?ScenarioDecisions $decisions = null): ProbeResult
    {
        $decisions ??= new ScenarioDecisions();
        $resolved = [];

        try {
            $browser->visit($scenario->context->startPath);
            $browser->settle();

            $countsBefore = [];
            foreach ($scenario->tablesCounted() as $table) {
                $countsBefore[$table] = $browser->countRows($table);
            }

            $scan = $browser->scan();
            $knownPanels = [];
            $this->remember($knownPanels, $scan);

            foreach ($scenario->steps as $step) {
                if (StepAction::Visit === $step->action) {
                    $path = (string) $step->target;
                    $browser->visit($path);
                    $browser->settle();
                    $scan = $browser->scan();
                    $this->remember($knownPanels, $scan);
                    $resolved[] = new ResolvedStep($step->index, null, null, null, null, $path);

                    continue;
                }

                $element = $decisions->get($step->index);
                if (null === $element) {
                    $resolution = $this->resolver->resolve($step, $scan);
                    if (ResolutionStatus::Resolved !== $resolution->status || null === $resolution->element) {
                        return ProbeResult::needsDecision($resolved, new PendingDecision(
                            $step->index,
                            $resolution->status,
                            array_map(static fn (Candidate $c) => $c->element, $resolution->candidates),
                            $scan->path(),
                        ));
                    }

                    $element = $resolution->element;
                }

                $optionValue = null;
                if (StepAction::Select === $step->action) {
                    $optionValue = $this->resolver->resolveOption($element, (string) $step->value);
                    if (null === $optionValue) {
                        $known = implode(', ', array_map(static fn (array $o): string => sprintf('« %s » (%s)', $o['text'], $o['value']), $element->options));

                        return ProbeResult::failed($resolved, sprintf('Étape %d : option « %s » introuvable dans la liste (options : %s).', $step->index, (string) $step->value, '' === $known ? 'aucune' : $known));
                    }
                }

                $target = $element->toTarget();
                try {
                    match ($step->action) {
                        StepAction::Click => $browser->click($target),
                        StepAction::Fill => $browser->fill($target, (string) $step->value),
                        StepAction::Select => $browser->selectOption($target, (string) $optionValue),
                        StepAction::Check => $browser->setChecked($target, true),
                        StepAction::Uncheck => $browser->setChecked($target, false),
                        StepAction::Visit => throw new \LogicException('Les navigations sont traitées avant la résolution.'),
                    };
                } catch (\Throwable $e) {
                    return ProbeResult::failed($resolved, sprintf('Étape %d (« %s ») : %s', $step->index, $step->raw, $e->getMessage()));
                }

                $browser->settle();
                $after = $browser->scan();
                $this->remember($knownPanels, $after);

                $beforeContainer = $scan->container?->selector;
                $afterContainer = $after->container?->selector;
                $changed = $beforeContainer !== $afterContainer;

                $resolved[] = new ResolvedStep(
                    $step->index,
                    $target,
                    $optionValue,
                    $changed ? $afterContainer : null,
                    $changed ? $beforeContainer : null,
                    $after->path() !== $scan->path() ? $after->path() : null,
                );
                $scan = $after;
            }

            return $this->checkExpectations($scenario, $browser, $scan, $knownPanels, $countsBefore, $resolved);
        } catch (\Throwable $e) {
            return ProbeResult::failed($resolved, $e->getMessage());
        }
    }

    /**
     * @param array<string, Panel>  $knownPanels
     * @param array<string, int>    $countsBefore
     * @param list<ResolvedStep>    $resolved
     */
    private function checkExpectations(
        FunctionalScenario $scenario,
        BrowserPort $browser,
        ScanResult $scan,
        array $knownPanels,
        array $countsBefore,
        array $resolved,
    ): ProbeResult {
        $expectations = [];
        $warnings = [];

        foreach ($scenario->expectations as $expectation) {
            $subject = (string) $expectation->subject;

            switch ($expectation->type) {
                case ExpectationType::UrlIs:
                    $actual = str_contains($subject, '?') ? $scan->url : $scan->path();
                    if ($actual !== $subject) {
                        return $this->unmet($resolved, $expectation, sprintf('l\'URL actuelle est « %s »', $actual));
                    }
                    break;

                case ExpectationType::UrlContains:
                    if (!str_contains($scan->url, $subject)) {
                        return $this->unmet($resolved, $expectation, sprintf('l\'URL actuelle est « %s »', $scan->url));
                    }
                    break;

                case ExpectationType::TextVisible:
                    if (!str_contains(TextNormalizer::clean($scan->bodyText), TextNormalizer::clean($subject))) {
                        $warnings[] = sprintf('Attente %d : le texte « %s » n\'a pas été retrouvé dans la page (la sonde lit les 3 000 premiers caractères).', $expectation->index, $subject);
                    }
                    break;

                case ExpectationType::TextAbsent:
                    if (str_contains(TextNormalizer::clean($scan->bodyText), TextNormalizer::clean($subject))) {
                        $warnings[] = sprintf('Attente %d : le texte « %s » est encore présent dans la page.', $expectation->index, $subject);
                    }
                    break;

                case ExpectationType::PanelOpen:
                case ExpectationType::PanelClosed:
                    $panel = $this->findPanel($subject, $scan, $knownPanels);
                    if (null === $panel) {
                        $titles = array_map(static fn (Panel $p): string => sprintf('« %s »', (string) $p->title), $knownPanels);

                        return $this->unmet($resolved, $expectation, sprintf('aucun panneau de ce titre n\'a été vu (panneaux connus : %s)', [] === $titles ? 'aucun' : implode(', ', $titles)));
                    }

                    $visibleNow = $this->isVisibleNow($panel, $scan);
                    $wantOpen = ExpectationType::PanelOpen === $expectation->type;
                    if ($visibleNow !== $wantOpen) {
                        return $this->unmet($resolved, $expectation, sprintf('le panneau est %s', $visibleNow ? 'ouvert' : 'fermé'));
                    }

                    $expectations[] = new ResolvedExpectation($expectation->index, $panel->selector);
                    continue 2;

                case ExpectationType::RowsDelta:
                    $before = $countsBefore[$subject] ?? 0;
                    $now = $browser->countRows($subject);
                    if ($now - $before !== $expectation->count) {
                        return $this->unmet($resolved, $expectation, sprintf('la table « %s » est passée de %d à %d ligne(s)', $subject, $before, $now));
                    }
                    break;

                case ExpectationType::RowsTotal:
                    $now = $browser->countRows($subject);
                    if ($now !== $expectation->count) {
                        return $this->unmet($resolved, $expectation, sprintf('la table « %s » contient %d ligne(s)', $subject, $now));
                    }
                    break;
            }

            $expectations[] = new ResolvedExpectation($expectation->index, null);
        }

        return ProbeResult::ok($resolved, $expectations, $warnings);
    }

    /**
     * @param list<ResolvedStep> $resolved
     */
    private function unmet(array $resolved, Expectation $expectation, string $observed): ProbeResult
    {
        return ProbeResult::failed($resolved, sprintf('Attente %d (« %s ») non constatée : %s.', $expectation->index, $expectation->raw, $observed));
    }

    /**
     * @param array<string, Panel> $known
     */
    private function remember(array &$known, ScanResult $scan): void
    {
        foreach ($scan->panels as $panel) {
            if (null !== $panel->title) {
                $known[TextNormalizer::key($panel->title)] = $panel;
            }
        }
    }

    /**
     * @param array<string, Panel> $known
     */
    private function findPanel(string $title, ScanResult $scan, array $known): ?Panel
    {
        $key = TextNormalizer::key($title);

        foreach ($scan->panels as $panel) {
            if (null !== $panel->title && TextNormalizer::key($panel->title) === $key) {
                return $panel;
            }
        }

        if (isset($known[$key])) {
            return $known[$key];
        }

        foreach ($known as $knownKey => $panel) {
            if ('' !== $key && str_contains($knownKey, $key)) {
                return $panel;
            }
        }

        return null;
    }

    private function isVisibleNow(Panel $panel, ScanResult $scan): bool
    {
        foreach ($scan->panels as $current) {
            if ($current->selector === $panel->selector) {
                return $current->visible;
            }
        }

        return false; // retiré de la page : fermé
    }
}
