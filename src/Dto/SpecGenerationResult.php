<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

readonly class SpecGenerationResult
{
    public function __construct(
        public SpecTargetPath $targetPath,
        public string $markdownContent,
        public ?string $jsonFilePath = null,
    ) {
    }

    /**
     * Raccourci pour récupérer directement le chemin du fichier Markdown.
     */
    public function getMarkdownFilePath(): string
    {
        return $this->targetPath->mdFilePath;
    }
}
