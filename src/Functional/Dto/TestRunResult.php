<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

/**
 * Résultat de l'exécution de PHPUnit sur le test généré.
 */
final class TestRunResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly bool $timedOut = false,
    ) {
    }

    public function tail(int $lines = 30): string
    {
        return (new ProcessResult($this->exitCode, $this->output, $this->timedOut))->tail($lines);
    }
}
