<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

use PhpParser\Node;

final readonly class QualityViolation
{
    public const PRIVATE_CALL = 'private-call';
    public const TRIVIAL_ASSERTION = 'trivial-assertion';
    public const TRIVIAL_ONLY = 'trivial-only';
    public const NO_TESTS = 'no-tests';

    public function __construct(
        public string $rule,
        public string $testClass,
        public string $test,
        public string $message,
        public ?Node $node, // test (private-call) ou instruction (trivial-assertion) : sert aux correcteurs
    ) {
    }
}