<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

/**
 * Faux locator Playwright : visibilité scriptée, appels enregistrés.
 */
final class FakeLocator
{
    /** @var list<string> */
    public array $calls = [];

    private int $visibilityReads = 0;

    /**
     * @param list<bool|\Throwable> $visibility résultats successifs de isVisible() (le dernier se répète)
     */
    public function __construct(
        public string $selector,
        private array $visibility = [true],
        private string $text = '',
        private ?\ArrayObject $log = null,
    ) {
    }

    public function locator(string $selector): self
    {
        return new self($this->selector . ' >> ' . $selector, log: $this->log);
    }

    public function click(): void
    {
        $this->record('click');
    }

    public function fill(string $value): void
    {
        $this->record('fill ' . $value);
    }

    public function selectOption(string|array $values): void
    {
        $this->record('select ' . (is_array($values) ? implode(',', $values) : $values));
    }

    public function check(): void
    {
        $this->record('check');
    }

    public function uncheck(): void
    {
        $this->record('uncheck');
    }

    public function isVisible(): bool
    {
        $value = $this->visibility[min($this->visibilityReads++, count($this->visibility) - 1)];
        if ($value instanceof \Throwable) {
            throw $value;
        }

        return $value;
    }

    public function innerText(): string
    {
        return $this->text;
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        $this->log?->append($this->selector . ' :: ' . $call);
    }
}
