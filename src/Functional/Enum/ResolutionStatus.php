<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Enum;

enum ResolutionStatus: string
{
    case Resolved = 'resolved';
    case Ambiguous = 'ambiguous';
    case NotFound = 'not_found';
}
