<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Probe\DomScanner;

/**
 * Fausse page Playwright : n'expose que les méthodes utilisées par le bundle.
 */
final class FakePage
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, object> */
    public array $locators = [];

    /** Journal partagé avec les locators créés : « sélecteur :: action ». */
    public \ArrayObject $log;

    /**
     * @param list<string>                    $urls        URL renvoyées successivement (la dernière se répète)
     * @param list<mixed>                     $evaluations réponses de evaluate() successives (la dernière se répète)
     * @param array<string, object>           $prepared    locators préparés, par sélecteur
     * @param array<string, mixed>|null       $scan        réponse de evaluate() au script de balayage du DOM
     */
    public function __construct(
        private array $urls = ['http://localhost/'],
        private array $evaluations = [],
        private array $prepared = [],
        private bool $networkIdleFails = false,
        private ?array $scan = null,
    ) {
        $this->log = new \ArrayObject();
    }

    public function locator(string $selector): object
    {
        $this->calls[] = 'locator ' . $selector;

        return $this->locators[$selector] ??= $this->prepared[$selector] ?? new FakeLocator($selector, log: $this->log);
    }

    public function url(): string
    {
        $url = array_shift($this->urls) ?? 'http://localhost/';
        if ([] === $this->urls) {
            $this->urls = [$url];
        }

        return $url;
    }

    public function evaluate(string $script): mixed
    {
        $this->calls[] = 'evaluate';
        if (null !== $this->scan && DomScanner::SCRIPT === $script) {
            return $this->scan;
        }

        if ([] === $this->evaluations) {
            return null;
        }

        $value = count($this->evaluations) > 1 ? array_shift($this->evaluations) : $this->evaluations[0];
        if ($value instanceof \Throwable) {
            throw $value;
        }

        return $value;
    }

    public function waitForLoadState(string $state): void
    {
        $this->calls[] = 'waitForLoadState ' . $state;
        if ($this->networkIdleFails) {
            throw new \RuntimeException('timeout');
        }
    }

    public function setDefaultTimeout(int $timeout): self
    {
        $this->calls[] = 'setDefaultTimeout ' . $timeout;

        return $this;
    }
}
