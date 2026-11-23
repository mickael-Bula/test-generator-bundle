<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Generation;

/**
 * Où écrire le test généré.
 */
final class GeneratedTestPath
{
    /**
     * @param string|null $output fichier .php, ou dossier ; relatif au projet s'il n'est pas absolu
     *
     * @throws \InvalidArgumentException si le dossier ne peut pas être déduit du namespace
     */
    public function resolve(string $projectDir, string $namespace, string $className, ?string $output = null): string
    {
        $projectDir = rtrim($projectDir, '/\\');

        if (null !== $output && '' !== trim($output)) {
            $output = $this->absolute($projectDir, trim($output));

            return str_ends_with(strtolower($output), '.php') ? $output : rtrim($output, '/\\') . '/' . $className . '.php';
        }

        $namespace = trim($namespace, '\\');
        if ('App\Tests' !== $namespace && !str_starts_with($namespace, 'App\Tests\\')) {
            throw new \InvalidArgumentException(sprintf('Impossible de déduire le dossier du namespace « %s » (attendu : App\Tests\…). Utilisez --output.', $namespace));
        }

        $relative = str_replace('\\', '/', substr($namespace, strlen('App\Tests')));

        return $projectDir . '/tests' . $relative . '/' . $className . '.php';
    }

    private function absolute(string $projectDir, string $path): string
    {
        return 1 === preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) ? $path : $projectDir . '/' . $path;
    }
}
