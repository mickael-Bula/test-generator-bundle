<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Mika\TestGeneratorBundle\Dto\SpecTargetPath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class SpecPathResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Résout le chemin vers le fichier de spécification Markdown.
     */
    public function resolve(string $outputDir, string $shortClassName): SpecTargetPath
    {
        $normalizedProjectDir = rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->projectDir),
            DIRECTORY_SEPARATOR
        );

        $normalizedOutputDir = trim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputDir),
            DIRECTORY_SEPARATOR
        );

        $targetDirectory = sprintf('%s%s%s', $normalizedProjectDir, DIRECTORY_SEPARATOR, $normalizedOutputDir);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            $this->filesystem->mkdir($targetDirectory);
        }

        $baseFilename = sprintf('%sSpec.md', $shortClassName);
        $mdFilePath = sprintf('%s%s%s', $targetDirectory, DIRECTORY_SEPARATOR, $baseFilename);

        return new SpecTargetPath($targetDirectory, $mdFilePath);
    }
}
