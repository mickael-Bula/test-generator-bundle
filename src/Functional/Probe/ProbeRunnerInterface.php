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
     * @throws ProbeException si la sonde n'a pas pu s'exécuter ou n'a rien rapporté
     */
    public function run(string $scenarioPath, ScenarioDecisions $decisions): ProbeResult;
}
