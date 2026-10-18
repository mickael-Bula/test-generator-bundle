<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

readonly class TestSnapshotStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/tmp')] private string $dir,
    ) {
    }

    public function save(string $testFilePath, string $code): void
    {
        (new Filesystem())->dumpFile($this->pathFor($testFilePath), $code);
    }

    /**
     * Instantané du test du rapport : par chemin de trace, sinon par nom de classe de test.
     */
    public function saveForReport(string $report, string $code): void
    {
        if (1 === preg_match('#(/\S+Test\.php):\d+#', $report, $m)) {
            $this->save($m[1], $code);
        } elseif (1 === preg_match('/^\d+\)\s+(?:[\w\\\\]+\\\\)?(?<short>\w+Test)::/m', $report, $t)) {
            $this->save($t['short'] . '.php', $code); // pathFor() ne retient que le nom du fichier
        }
    }

    /** Chemin de l'instantané du dernier run, ou null. */
    public function find(string $testFilePath): ?string
    {
        $path = $this->pathFor($testFilePath);

        return is_file($path) ? $path : null;
    }

    // basename : robuste si le chemin de la trace diffère de projectDir (lien symbolique)
    private function pathFor(string $testFilePath): string
    {
        return rtrim($this->dir, '/\\') . '/snapshot_' . basename($testFilePath) . '.txt';
    }
}
