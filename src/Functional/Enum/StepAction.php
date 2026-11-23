<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Enum;

enum StepAction: string
{
    case Click = 'click';
    case Fill = 'fill';
    case Select = 'select';
    case Check = 'check';
    case Uncheck = 'uncheck';
    case Visit = 'visit';
}
