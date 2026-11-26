<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Support\ScenarioProbeTrait;

/**
 * Équivalent de la classe MikaScenarioProbe que l'hôte doit créer.
 */
final class ProbeHostStub extends ContractE2ETest
{
    use ScenarioProbeTrait;

    /**
     * @throws \JsonException
     */
    public function run(): void
    {
        $this->runScenarioProbe();
    }
}
