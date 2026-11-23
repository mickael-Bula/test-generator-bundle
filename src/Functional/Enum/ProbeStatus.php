<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Enum;

enum ProbeStatus: string
{
    case Ok = 'ok';
    case NeedsDecision = 'needs_decision';
    case Failed = 'failed';
}
