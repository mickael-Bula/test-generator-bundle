<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

/**
 * Résultat d'un processus externe : code de sortie, sortie (standard + erreur) et indicateur de délai dépassé.
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly bool $timedOut = false,
    ) {
    }

    /**
     * Les dernières lignes de la sortie.
     */
    public function tail(int $lines = 30): string
    {
        $all = preg_split('/\R/', trim($this->output)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
