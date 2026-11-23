<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Runtime;

use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Dto\Target;

/**
 * Ce dont la sonde a besoin de la part d'un navigateur. L'implémentation Playwright vit dans l'hôte
 * (via le trait de sonde) ; les tests du bundle utilisent un faux navigateur.
 */
interface BrowserPort
{
    public function visit(string $path): void;

    /**
     * Instantané des éléments interactifs visibles, du panneau ouvert et de la page.
     */
    public function scan(): ScanResult;

    public function click(Target $target): void;

    public function fill(Target $target, string $value): void;

    public function selectOption(Target $target, string $optionValue): void;

    public function setChecked(Target $target, bool $checked): void;

    /**
     * Attend que la page se stabilise (réseau calme, DOM stable) avant de la scanner.
     */
    public function settle(): void;

    public function countRows(string $table): int;
}
