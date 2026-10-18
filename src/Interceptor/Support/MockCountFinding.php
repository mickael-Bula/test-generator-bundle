<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Node;

final readonly class MockCountFinding
{
    public function __construct(
        public string $kind,                          // 'too-many' | 'wrong-count'
        public string $test,
        public string $method,                        // méthode stubbée du mock
        public ?int $observed,                        // nombre d'appels constaté par PHPUnit (wrong-count)
        public ?int $derived,                         // nombre d'appels déduit du code du SUT, null si indéterminé
        public string $derivation,                    // explication courte, ou raison de l'indétermination
        public ?Node\Expr $matcher,                   // argument de expects() : once() / exactly(N)
        public ?int $limit,                           // nombre actuellement attendu
        public ?Node\Stmt\Expression $expectation,    // instruction `expects(...)->method(...)`
        public ?Node\Stmt $moveBefore,                // ancre si l'attente est déclarée APRÈS l'appel du SUT
    ) {
    }
}