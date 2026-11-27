<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Probe\ProbeRunnerInterface;

/**
 * Sonde de test aux réponses écrites à l'avance (la dernière se répète).
 */
final class ScriptedProbeRunner implements ProbeRunnerInterface
{
    public int $runs = 0;

    /**
     * @param list<ProbeResult|\Throwable> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function run(string $scenarioPath, ScenarioDecisions $decisions, ?string $baseClass = null): ProbeResult
    {
        ++$this->runs;
        $response = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
