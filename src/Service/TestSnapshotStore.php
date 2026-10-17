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
