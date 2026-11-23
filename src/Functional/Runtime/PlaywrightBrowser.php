<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Runtime;

use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\Target;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use Mika\TestGeneratorBundle\Functional\Probe\DomScanner;

/**
 * BrowserPort de la sonde, branché sur une page Playwright PHP.
 */
final class PlaywrightBrowser implements BrowserPort
{
    private ?object $page = null;

    /**
     * @param \Closure(string): object $visit     ouvre un chemin et renvoie la page Playwright (ex. fn ($p) => $this->open($p))
     * @param \Closure(string): int    $countRows nombre de lignes d'une table (via la connexion du test, donc la transaction DAMA)
     */
    public function __construct(
        private readonly \Closure $visit,
        private readonly \Closure $countRows,
        private readonly PlaywrightActions $actions = new PlaywrightActions(),
    ) {
    }

    public function visit(string $path): void
    {
        $this->page = ($this->visit)($path);
    }

    public function scan(): ScanResult
    {
        return DomScanner::parse($this->page()->evaluate(DomScanner::SCRIPT));
    }

    public function click(Target $target): void
    {
        $this->locator($target)->click();
    }

    public function fill(Target $target, string $value): void
    {
        $this->locator($target)->fill($value);
    }

    public function selectOption(Target $target, string $optionValue): void
    {
        $this->actions->selectValue($this->locator($target), $optionValue);
    }

    public function setChecked(Target $target, bool $checked): void
    {
        $this->actions->setChecked($this->locator($target), $checked);
    }

    public function settle(): void
    {
        $this->actions->settle($this->page());
    }

    public function countRows(string $table): int
    {
        return ($this->countRows)($table);
    }

    private function locator(Target $target): object
    {
        $page = $this->page();

        return null !== $target->container
            ? $page->locator($target->container)->locator($target->relative)
            : $page->locator($target->selector);
    }

    private function page(): object
    {
        return $this->page ?? throw new ProbeException('Aucune page ouverte : visit() doit être appelé en premier.');
    }
}
