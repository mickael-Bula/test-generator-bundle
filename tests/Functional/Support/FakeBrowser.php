<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\Target;
use Mika\TestGeneratorBundle\Functional\Probe\DomScanner;
use Mika\TestGeneratorBundle\Functional\Runtime\BrowserPort;

/**
 * Faux navigateur à états : chaque état renvoie un instantané réel (Fixture/*.json) et certains
 * clics font passer d'un état à l'autre, éventuellement en ajoutant une ligne en base.
 */
final class FakeBrowser implements BrowserPort
{
    /** @var list<string> */
    public array $log = [];

    public int $rows = 10;

    /** Nombre de scans pendant lesquels l'ancien état reste affiché après le clic sur $lagSelector (page lente à réagir). */
    public int $lagScans = 0;
    public string $lagSelector = '#offcanvasNewPositionwaiting button[type="submit"]';

    private ?string $pendingState = null;
    private int $pendingRows = 0;
    private int $pendingScans = 0;

    /**
     * @param array<string, ScanResult>                                 $scans   état => instantané
     * @param array<string, array{to: string, addRows?: int}>           $clicks  sélecteur complet => transition
     * @param list<string>                                              $missing sélecteurs que le navigateur ne trouve pas
     */
    public function __construct(
        private array $scans,
        private array $clicks,
        private string $state,
        private array $missing = [],
    ) {
    }

    public static function realisticApp(): self
    {
        $dashboard = DomScanner::parse((string) file_get_contents(__DIR__ . '/../Fixture/scan-dashboard.json'));
        $panel = DomScanner::parse((string) file_get_contents(__DIR__ . '/../Fixture/scan-new-position-open.json'));

        return new self(
            ['dashboard' => $dashboard, 'panel' => $panel],
            [
                'button[data-bs-target="#offcanvasNewPositionwaiting"]' => ['to' => 'panel'],
                '#offcanvasNewPositionwaiting button[type="submit"]' => ['to' => 'dashboard', 'addRows' => 1],
                '#offcanvasNewPositionwaiting button[aria-label="Close"]' => ['to' => 'dashboard'],
            ],
            'dashboard',
        );
    }

    public function visit(string $path): void
    {
        $this->log[] = 'visit ' . $path;
    }

    public function scan(): ScanResult
    {
        if (null !== $this->pendingState && --$this->pendingScans <= 0) {
            $this->state = $this->pendingState;
            $this->rows += $this->pendingRows;
            $this->pendingState = null;
            $this->pendingRows = 0;
        }

        return $this->scans[$this->state];
    }

    public function click(Target $target): void
    {
        $this->guard($target);
        $this->log[] = 'click ' . $target->selector;

        if (!isset($this->clicks[$target->selector])) {
            return;
        }

        $transition = $this->clicks[$target->selector];
        if ($this->lagScans > 0 && $target->selector === $this->lagSelector) {
            $this->pendingState = $transition['to'];
            $this->pendingRows = $transition['addRows'] ?? 0;
            $this->pendingScans = $this->lagScans;

            return;
        }

        $this->state = $transition['to'];
        $this->rows += $transition['addRows'] ?? 0;
    }

    public function fill(Target $target, string $value): void
    {
        $this->guard($target);
        $this->log[] = sprintf('fill %s = %s', $target->selector, $value);
    }

    public function selectOption(Target $target, string $optionValue): void
    {
        $this->guard($target);
        $this->log[] = sprintf('select %s = %s', $target->selector, $optionValue);
    }

    public function setChecked(Target $target, bool $checked): void
    {
        $this->guard($target);
        $this->log[] = sprintf('%s %s', $checked ? 'check' : 'uncheck', $target->selector);
    }

    public function settle(): void
    {
    }

    public function countRows(string $table): int
    {
        return $this->rows;
    }

    private function guard(Target $target): void
    {
        if (in_array($target->selector, $this->missing, true)) {
            throw new \RuntimeException(sprintf('Élément introuvable : %s', $target->selector));
        }
    }
}
