<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Render;

use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\Target;
use Mika\TestGeneratorBundle\Functional\Enum\ExpectationType;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Text\TextNormalizer;

/**
 * Gabarit de génération : écrit le test PHPUnit à partir du scénario et du résultat de la sonde.
 * Entièrement déterministe, sans LLM.
 *
 * Le test produit utilise les aides de FunctionalTestHelpersTrait (open, countRows, loginAs, waitUntil*,
 * selectValue, setChecked) et l'API Playwright éprouvée (locator, click, fill, isVisible, url).
 * Seuls les marqueurs BDD nus sont écrits en commentaire.
 */
final class FunctionalTestRenderer
{
    public function render(FunctionalScenario $scenario, ProbeResult $probe, RenderOptions $options): string
    {
        if (ProbeStatus::Ok !== $probe->status) {
            throw new \LogicException('Le résultat de la sonde doit être « ok » pour générer le test.');
        }

        $pascal = $this->pascalCase($scenario->title);
        $className = $this->className($scenario, $options);
        $baseShort = $this->shortName($options->baseClass);
        $baseNamespace = $this->namespaceOf($options->baseClass);

        $given = $this->given($scenario);
        $containers = [];
        $when = $this->when($scenario, $probe, $containers);
        $then = $this->then($scenario, $probe, $containers);

        // Une attente déjà posée à la fin du QUAND n'est pas répétée en tête du ALORS.
        if ([] !== $when && [] !== $then && $then[0] === end($when) && str_starts_with($then[0], '$this->waitUntil')) {
            array_shift($then);
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $options->namespace . ';',
            '',
        ];

        if ($baseNamespace !== $options->namespace) {
            $lines[] = 'use ' . ltrim($options->baseClass, '\\') . ';';
            $lines[] = '';
        }

        $lines[] = sprintf('final class %s extends %s', $className, $baseShort);
        $lines[] = '{';
        $lines[] = sprintf('    public function test%s(): void', $pascal);
        $lines[] = '    {';

        foreach ([['ÉTANT DONNÉ', $given], ['QUAND', $when], ['ALORS', $then]] as $index => [$marker, $body]) {
            if ($index > 0) {
                $lines[] = '';
            }

            $lines[] = '        // ' . $marker;
            foreach ($body as $line) {
                $lines[] = '        ' . $line;
            }
        }

        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Nom de la classe générée : celui demandé, sinon dérivé du titre du scénario.
     */
    public function className(FunctionalScenario $scenario, RenderOptions $options): string
    {
        return $options->className ?? $this->pascalCase($scenario->title) . 'DynamicTest';
    }

    /**
     * @return list<string>
     */
    private function given(FunctionalScenario $scenario): array
    {
        $lines = [];
        if (null !== $scenario->context->authStrategy) {
            $lines[] = sprintf('$this->loginAs(%s);', $this->quote($scenario->context->authProfile ?? 'default'));
        }

        $lines[] = sprintf('$page = $this->open(%s);', $this->quote($scenario->context->startPath));

        foreach ($scenario->tablesCounted() as $table) {
            $lines[] = sprintf('%s = $this->countRows(%s);', $this->countVariable($table), $this->quote($table));
        }

        return $lines;
    }

    /**
     * @param array<string, string> $containers sélecteur de panneau => variable
     *
     * @return list<string>
     */
    private function when(FunctionalScenario $scenario, ProbeResult $probe, array &$containers): array
    {
        $lines = [];

        foreach ($scenario->steps as $step) {
            $resolved = $probe->stepFor($step->index)
                ?? throw new \LogicException(sprintf('La sonde n\'a pas résolu l\'étape %d.', $step->index));

            if (StepAction::Visit === $step->action) {
                $lines[] = sprintf('$page = $this->open(%s);', $this->quote((string) $step->target));

                continue;
            }

            $target = $resolved->target
                ?? throw new \LogicException(sprintf('L\'étape %d n\'a pas de cible résolue.', $step->index));
            $locator = $this->locator($target, $containers, $lines);

            $lines[] = match ($step->action) {
                StepAction::Click => $locator . '->click();',
                StepAction::Fill => sprintf('%s->fill(%s);', $locator, $this->quote((string) $step->value)),
                StepAction::Select => sprintf('$this->selectValue(%s, %s);', $locator, $this->quote((string) $resolved->optionValue)),
                StepAction::Check => sprintf('$this->setChecked(%s, true);', $locator),
                StepAction::Uncheck => sprintf('$this->setChecked(%s, false);', $locator),
                StepAction::Visit => throw new \LogicException('Navigation déjà traitée.'),
            };

            if (null !== $resolved->opensContainer) {
                $lines[] = sprintf('$this->waitUntilVisible(%s);', $this->container($resolved->opensContainer, $containers, $lines));
            }

            if (null !== $resolved->closesContainer) {
                $lines[] = sprintf('$this->waitUntilHidden(%s);', $this->container($resolved->closesContainer, $containers, $lines));
            }

            if (null !== $resolved->pathAfter) {
                $lines[] = sprintf('$this->waitUntilUrl($page, %s);', $this->quote($resolved->pathAfter));
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $containers
     *
     * @return list<string>
     */
    private function then(FunctionalScenario $scenario, ProbeResult $probe, array &$containers): array
    {
        $lines = [];

        foreach ($scenario->expectations as $expectation) {
            $subject = (string) $expectation->subject;

            switch ($expectation->type) {
                case ExpectationType::UrlIs:
                    $lines[] = sprintf('$this->waitUntilUrl($page, %s);', $this->quote($subject));
                    $lines[] = sprintf('self::assertSame(%s, $this->currentPath($page, %s));', $this->quote($subject), $this->quote($subject));
                    break;

                case ExpectationType::UrlContains:
                    $lines[] = sprintf('$this->waitUntilUrlContains($page, %s);', $this->quote($subject));
                    $lines[] = sprintf('self::assertStringContainsString(%s, (string) $page->url());', $this->quote($subject));
                    break;

                case ExpectationType::TextVisible:
                    $lines[] = sprintf('$this->waitUntilTextVisible($page, %s);', $this->quote($subject));
                    $lines[] = sprintf('self::assertStringContainsString(%s, $this->visibleText($page));', $this->quote(TextNormalizer::clean($subject)));
                    break;

                case ExpectationType::TextAbsent:
                    $lines[] = sprintf('$this->waitUntilTextGone($page, %s);', $this->quote($subject));
                    $lines[] = sprintf('self::assertStringNotContainsString(%s, $this->visibleText($page));', $this->quote(TextNormalizer::clean($subject)));
                    break;

                case ExpectationType::PanelOpen:
                case ExpectationType::PanelClosed:
                    $selector = $probe->expectationFor($expectation->index)?->panelSelector
                        ?? throw new \LogicException(sprintf('L\'attente %d vise un panneau que la sonde n\'a pas résolu.', $expectation->index));
                    $panel = $this->container($selector, $containers, $lines);

                    if (ExpectationType::PanelOpen === $expectation->type) {
                        $lines[] = sprintf('$this->waitUntilVisible(%s);', $panel);
                        $lines[] = sprintf('self::assertTrue(%s->isVisible());', $panel);
                    } else {
                        $lines[] = sprintf('$this->waitUntilHidden(%s);', $panel);
                        $lines[] = sprintf('self::assertFalse(%s->isVisible());', $panel);
                    }
                    break;

                case ExpectationType::RowsDelta:
                    $count = (int) $expectation->count;
                    $variable = $this->countVariable($subject);
                    $expected = match (true) {
                        $count > 0 => sprintf('%s + %d', $variable, $count),
                        $count < 0 => sprintf('%s - %d', $variable, -$count),
                        default => $variable,
                    };
                    $lines[] = sprintf('self::assertSame(%s, $this->countRows(%s));', $expected, $this->quote($subject));
                    break;

                case ExpectationType::RowsTotal:
                    $lines[] = sprintf('self::assertSame(%d, $this->countRows(%s));', (int) $expectation->count, $this->quote($subject));
                    break;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $containers
     * @param list<string>          $lines
     */
    private function locator(Target $target, array &$containers, array &$lines): string
    {
        if (null === $target->container) {
            return sprintf('$page->locator(%s)', $this->quote($target->selector));
        }

        return sprintf('%s->locator(%s)', $this->container($target->container, $containers, $lines), $this->quote($target->relative));
    }

    /**
     * Variable désignant un panneau ; sa définition est ajoutée à la première utilisation.
     *
     * @param array<string, string> $containers
     * @param list<string>          $lines
     */
    private function container(string $selector, array &$containers, array &$lines): string
    {
        if (!isset($containers[$selector])) {
            $containers[$selector] = [] === $containers ? '$panel' : '$panel' . (count($containers) + 1);
            $lines[] = sprintf('%s = $page->locator(%s);', $containers[$selector], $this->quote($selector));
        }

        return $containers[$selector];
    }

    private function quote(string $value): string
    {
        return var_export($value, true);
    }

    private function countVariable(string $table): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $table, -1, PREG_SPLIT_NO_EMPTY) ?: ['table'];
        $name = lcfirst(implode('', array_map('ucfirst', $parts)));

        return '$' . $name . 'RowsBefore';
    }

    private function pascalCase(string $title): string
    {
        $parts = preg_split('/[^a-z0-9]+/', TextNormalizer::fold($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = implode('', array_map('ucfirst', $parts));

        if ('' === $name) {
            return 'Scenario';
        }

        return ctype_digit($name[0]) ? 'S' . $name : $name;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));

        return (string) end($parts);
    }

    private function namespaceOf(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        array_pop($parts);

        return implode('\\', $parts);
    }
}
