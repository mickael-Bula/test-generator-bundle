<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

enum Status: string
{
    case Open = 'open';
    case Closed = 'closed';
    case PENDING_REVIEW = 'pending_review';
}
