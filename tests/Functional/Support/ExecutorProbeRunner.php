<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use Mika\TestGeneratorBundle\Functional\Probe\ProbeRunnerInterface;
use Mika\TestGeneratorBundle\Functional\Runtime\ScenarioExecutor;

/**
 * Sonde de test : exécute le vrai ScenarioExecutor sur l'application factice, sans navigateur ni processus.
 */
final class ExecutorProbeRunner implements ProbeRunnerInterface
{
    /** @var list<ScenarioDecisions> */
    public array $decisionsSeen = [];

    /** @var list<string|null> */
    public array $baseClasses = [];

    public function run(string $scenarioPath, ScenarioDecisions $decisions, ?string $baseClass = null): ProbeResult
    {
        $this->decisionsSeen[] = $decisions;
        $this->baseClasses[] = $baseClass;

        return (new ScenarioExecutor(expectationTimeoutMs: 0))->execute((new FunctionalScenarioParser())->parseFile($scenarioPath), FakeBrowser::realisticApp(), $decisions);
    }
}
