<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;

final readonly class ProbeResult
{
    /**
     * @param list<ResolvedStep>        $steps
     * @param list<ResolvedExpectation> $expectations
     * @param list<string>              $warnings     attentes que la sonde n'a pas pu confirmer (le test les vérifiera)
     */
    public function __construct(
        public ProbeStatus $status,
        public array $steps = [],
        public array $expectations = [],
        public ?PendingDecision $pending = null,
        public ?string $error = null,
        public array $warnings = [],
    ) {
    }

    /**
     * @param list<ResolvedStep>        $steps
     * @param list<ResolvedExpectation> $expectations
     * @param list<string>              $warnings
     */
    public static function ok(array $steps, array $expectations, array $warnings = []): self
    {
        return new self(ProbeStatus::Ok, $steps, $expectations, null, null, $warnings);
    }

    /**
     * @param list<ResolvedStep> $steps
     */
    public static function needsDecision(array $steps, PendingDecision $pending): self
    {
        return new self(ProbeStatus::NeedsDecision, $steps, [], $pending);
    }

    /**
     * @param list<ResolvedStep> $steps
     */
    public static function failed(array $steps, string $error): self
    {
        return new self(ProbeStatus::Failed, $steps, [], null, $error);
    }

    public function stepFor(int $stepIndex): ?ResolvedStep
    {
        foreach ($this->steps as $step) {
            if ($step->stepIndex === $stepIndex) {
                return $step;
            }
        }

        return null;
    }

    public function expectationFor(int $expectationIndex): ?ResolvedExpectation
    {
        foreach ($this->expectations as $expectation) {
            if ($expectation->expectationIndex === $expectationIndex) {
                return $expectation;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'steps' => array_map(static fn (ResolvedStep $s): array => $s->toArray(), $this->steps),
            'expectations' => array_map(static fn (ResolvedExpectation $e): array => $e->toArray(), $this->expectations),
            'pending' => $this->pending?->toArray(),
            'error' => $this->error,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];
        foreach ((array) ($data['steps'] ?? []) as $step) {
            if (is_array($step)) {
                $steps[] = ResolvedStep::fromArray($step);
            }
        }

        $expectations = [];
        foreach ((array) ($data['expectations'] ?? []) as $expectation) {
            if (is_array($expectation)) {
                $expectations[] = ResolvedExpectation::fromArray($expectation);
            }
        }

        return new self(
            ProbeStatus::from((string) ($data['status'] ?? 'failed')),
            $steps,
            $expectations,
            isset($data['pending']) && is_array($data['pending']) ? PendingDecision::fromArray($data['pending']) : null,
            isset($data['error']) ? (string) $data['error'] : null,
            array_values(array_map('strval', (array) ($data['warnings'] ?? []))),
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Relit le fichier écrit par la sonde.
     *
     * @throws ProbeException si le JSON est illisible ou n'est pas un résultat de sonde
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProbeException('Résultat de sonde illisible : ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || !isset($data['status']) || null === ProbeStatus::tryFrom((string) $data['status'])) {
            throw new ProbeException('Résultat de sonde invalide : statut absent ou inconnu.');
        }

        return self::fromArray($data);
    }
}
