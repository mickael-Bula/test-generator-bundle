<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Generation;

/**
 * Sort le test généré de la zone d'attente : vers le dossier des tests validés s'il est vert,
 * vers le dossier des tests en échec (accompagné du journal PHPUnit) sinon.
 */
final readonly class GeneratedTestDelivery
{
    public function __construct(private GeneratedTestPath $paths = new GeneratedTestPath())
    {
    }

    /**
     * Déplace le test vert dans $directory ; son namespace est aligné sur le dossier d'arrivée s'il se trouve sous tests/.
     *
     * @throws \RuntimeException si la destination existe sans --force ou si l'écriture échoue
     */
    public function promote(string $projectDir, string $stagingFile, string $directory, string $baseClass, bool $force): string
    {
        $target = $directory . '/' . basename($stagingFile);
        if (is_file($target) && $target !== $stagingFile && !$force) {
            throw new \RuntimeException(sprintf('Le fichier existe déjà : %s (utilisez --force pour l\'écraser).', $target));
        }

        $source = $this->read($stagingFile);
        $namespace = $this->paths->namespaceFor($projectDir, $directory);
        if (null !== $namespace) {
            $source = $this->relocate($source, $namespace, $baseClass);
        }

        $this->write($target, $source);
        $this->discard($stagingFile, $target);

        return $target;
    }

    /**
     * Déplace le test rouge dans $directory avec le journal PHPUnit à côté (même nom, extension .log).
     *
     * @return array{0: string, 1: string} chemin du test et chemin du journal
     */
    public function quarantine(string $stagingFile, string $directory, string $log): array
    {
        $target = $directory . '/' . basename($stagingFile);
        $logFile = preg_replace('/\.php$/i', '', $target) . '.log';

        $this->write($target, $this->read($stagingFile));
        $this->write($logFile, $log);
        $this->discard($stagingFile, $target);

        return [$target, $logFile];
    }

    /**
     * Réécrit le namespace du source, et la ligne `use` de la classe de base si elle devient nécessaire ou inutile.
     */
    public function relocate(string $source, string $namespace, string $baseClass): string
    {
        $baseClass = ltrim($baseClass, '\\');
        $baseNamespace = false === ($pos = strrpos($baseClass, '\\')) ? '' : substr($baseClass, 0, $pos);

        $source = (string) preg_replace('/^use ' . preg_quote($baseClass, '/') . ';\R(?:\R)?/m', '', $source, 1);

        $header = 'namespace ' . $namespace . ';';
        if ($baseNamespace !== $namespace) {
            $header .= "\n\nuse " . $baseClass . ';';
        }

        return (string) preg_replace('/^namespace [^;]+;/m', str_replace('\\', '\\\\', $header), $source, 1);
    }

    private function read(string $file): string
    {
        $content = file_get_contents($file);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Impossible de lire : %s', $file));
        }

        return $content;
    }

    private function write(string $file, string $content): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier : %s', $directory));
        }

        if (false === file_put_contents($file, $content)) {
            throw new \RuntimeException(sprintf('Impossible d\'écrire : %s', $file));
        }
    }

    private function discard(string $stagingFile, string $target): void
    {
        if ($stagingFile !== $target && is_file($stagingFile)) {
            unlink($stagingFile);
        }
    }
}
