<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Enum;

enum GenerationStatus: string
{
    case Generated = 'generated';
    case NeedsDecision = 'needs_decision';
    case Failed = 'failed';
}
