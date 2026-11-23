<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\GenerationStatus;

/**
 * Résultat d'une génération : le code produit, ou ce qui a empêché de le produire.
 */
final readonly class GenerationReport
{
    /**
     * @param list<StepReport> $steps
     * @param list<string>     $warnings
     */
    private function __construct(
        public GenerationStatus $status,
        public string $title,
        public string $className,
        public ?string $source,
        public array $steps,
        public array $warnings,
        public ?string $error,
        public ?Step $pendingStep,
        public ?PendingDecision $pending,
        public int $probeRuns,
    ) {
    }

    /**
     * @param list<StepReport> $steps
     * @param list<string>     $warnings
     */
    public static function generated(string $title, string $className, string $source, array $steps, array $warnings, int $probeRuns): self
    {
        return new self(GenerationStatus::Generated, $title, $className, $source, $steps, $warnings, null, null, null, $probeRuns);
    }

    /**
     * @param list<StepReport> $steps
     */
    public static function needsDecision(string $title, string $className, array $steps, Step $pendingStep, PendingDecision $pending, int $probeRuns): self
    {
        return new self(GenerationStatus::NeedsDecision, $title, $className, null, $steps, [], null, $pendingStep, $pending, $probeRuns);
    }

    /**
     * @param list<StepReport> $steps
     * @param list<string>     $warnings
     */
    public static function failed(string $title, string $className, array $steps, string $error, int $probeRuns, array $warnings = []): self
    {
        return new self(GenerationStatus::Failed, $title, $className, null, $steps, $warnings, $error, null, null, $probeRuns);
    }
}
