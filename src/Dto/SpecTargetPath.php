<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

final readonly class SpecTargetPath
{
    public function __construct(
        public string $targetDirectory,
        public string $mdFilePath,
    ) {
    }

    /**
     * Vérifie si le dossier de spécification existe sur le disque.
     */
    public function dirExists(): bool
    {
        return is_dir($this->targetDirectory);
    }

    /**
     * Vérifie si le fichier de spécification existe déjà sur le disque.
     */
    public function fileExists(): bool
    {
        return file_exists($this->mdFilePath);
    }

    /**
     * Retourne le nom du fichier seul (ex : FooSpec.md).
     */
    public function getFilename(): string
    {
        return basename($this->mdFilePath);
    }
}
