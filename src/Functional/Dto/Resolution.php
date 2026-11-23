<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;

final readonly class Resolution
{
    /**
     * @param list<Candidate> $candidates candidats retenus (un seul si résolu, les plus proches sinon)
     */
    public function __construct(
        public ResolutionStatus $status,
        public ?ScannedElement $element,
        public array $candidates,
    ) {
    }
}
