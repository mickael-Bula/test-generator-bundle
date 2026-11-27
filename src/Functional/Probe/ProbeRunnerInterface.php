<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Probe;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;

/**
 * Lance la sonde sur un scénario et en rapporte le résultat.
 */
interface ProbeRunnerInterface
{
    /**
     * @param string|null $baseClass classe de base E2E de l'hôte, pour générer la classe de sonde si l'hôte n'en fournit pas
     *
     * @throws ProbeException si la sonde n'a pas pu s'exécuter ou n'a rien rapporté
     */
    public function run(string $scenarioPath, ScenarioDecisions $decisions, ?string $baseClass = null): ProbeResult;
}
