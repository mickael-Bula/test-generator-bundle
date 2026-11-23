<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\ExpectationType;

final readonly class FunctionalScenario
{
    /**
     * @param list<Step>        $steps
     * @param list<Expectation> $expectations
     */
    public function __construct(
        public string $title,
        public string $sourcePath,
        public ScenarioContext $context,
        public array $steps,
        public array $expectations,
    ) {
    }

    /**
     * Tables dont une attente compare le nombre de lignes, dans l'ordre d'apparition.
     *
     * @return list<string>
     */
    public function tablesCounted(): array
    {
        $tables = [];
        foreach ($this->expectations as $expectation) {
            if (ExpectationType::RowsDelta === $expectation->type && null !== $expectation->subject) {
                $tables[$expectation->subject] = $expectation->subject;
            }
        }

        return array_values($tables);
    }
}
