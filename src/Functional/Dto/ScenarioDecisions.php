<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

/**
 * Éléments choisis (par le LLM ou l'auteur) pour les étapes que les règles n'ont pas pu résoudre.
 * Échangé sous forme de JSON entre la commande et le processus de sonde.
 */
final readonly class ScenarioDecisions
{
    /**
     * @param array<int, ScannedElement> $byStep
     */
    public function __construct(private array $byStep = [])
    {
    }

    public function get(int $stepIndex): ?ScannedElement
    {
        return $this->byStep[$stepIndex] ?? null;
    }

    public function with(int $stepIndex, ScannedElement $element): self
    {
        return new self([$stepIndex => $element] + $this->byStep);
    }

    public function isEmpty(): bool
    {
        return [] === $this->byStep;
    }

    /**
     * @return list<int>
     */
    public function stepIndexes(): array
    {
        $indexes = array_keys($this->byStep);
        sort($indexes);

        return $indexes;
    }

    public function toJson(): string
    {
        $data = [];
        foreach ($this->stepIndexes() as $index) {
            $data[(string) $index] = $this->byStep[$index]->toArray();
        }

        return json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $byStep = [];
        foreach ((array) $data as $index => $element) {
            if (is_array($element)) {
                $byStep[(int) $index] = ScannedElement::fromArray($element);
            }
        }

        return new self($byStep);
    }
}
