<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture ;

enum Verdict: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
