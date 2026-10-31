<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Manager;

use Mika\TestGeneratorBundle\Dto\SpecGenerationResult;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Mika\TestGeneratorBundle\Llm\SpecGeneratorAgent;
use Mika\TestGeneratorBundle\Reflection\ObjectExporter;
use Mika\TestGeneratorBundle\Renderer\SpecMarkdownRenderer;
use Mika\TestGeneratorBundle\Resolver\SpecPathResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

readonly class SpecManager
{
    public function __construct(
        private SpecPathResolver $specPathResolver,
        private SpecGeneratorAgent $specGeneratorAgent,
        private SpecMarkdownRenderer $markdownRenderer,
        private Filesystem $filesystem,
        #[Autowire('%env(bool:default::MIKA_TEST_TRACE)%')] private bool $trace = false,
    ) {
    }

    /**
     * Vérifie si un fichier de spécification existe selon les conventions par défaut.
     */
    public function hasSpec(string $shortClassName, string $outputDir = 'tests/Specs'): bool
    {
        return $this->specPathResolver->resolve($outputDir, $shortClassName)->fileExists();
    }

    /**
     * Retourne le chemin du fichier de spécification résolu.
     */
    public function getSpecFilePath(string $shortClassName, string $outputDir = 'tests/Specs'): string
    {
        return $this->specPathResolver->resolve($outputDir, $shortClassName)->mdFilePath;
    }

    /**
     * Génère et sauvegarde le fichier de spécification Markdown (et optionnellement JSON).
     *
     * @throws \JsonException|TestGenerationException|\ReflectionException
     */
    public function generateAndSaveSpec(
        string $shortClassName,
        string $classCode,
        string $fqcn,
        ?string $methodName,
        string $testType,
        string $model,
        ?string $provider,
        string $outputDir = 'tests/Specs',
        bool $dumpJson = false,
    ): SpecGenerationResult {
        $targetPath = $this->specPathResolver->resolve($outputDir, $shortClassName);

        $specData = $this->specGeneratorAgent->generateSpec(
            classCode: $classCode,
            fqcn: $fqcn,
            methodName: $methodName,
            type: $testType,
            model: $model,
            provider: $provider
        );

        $markdownContent = $this->markdownRenderer->render($specData);

        if (!$targetPath->dirExists()) {
            $this->filesystem->mkdir($targetPath->targetDirectory);
        }

        $this->filesystem->dumpFile($targetPath->mdFilePath, $markdownContent);

        // Sauvegarde optionnelle du JSON : à la demande, ou avec MIKA_TEST_TRACE=1
        $jsonFilePath = null;
        if ($dumpJson || $this->trace) {
            $jsonFilePath = sprintf('%s%s%sSpec.json', $targetPath->targetDirectory, DIRECTORY_SEPARATOR, $shortClassName);

            $this->filesystem->dumpFile($jsonFilePath, json_encode(
                ObjectExporter::toArray($specData),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        return new SpecGenerationResult(
            targetPath: $targetPath,
            markdownContent: $markdownContent,
            jsonFilePath: $jsonFilePath
        );
    }

    /**
     * Récupère le contenu de la spec depuis un chemin personnalisé,
     * ou par convention (la spécification est créée si elle n'existe pas).
     *
     * @throws \JsonException|TestGenerationException|\ReflectionException
     */
    public function resolveOrGenerateSpecContent(
        string $shortClassName,
        string $classCode,
        string $fqcn,
        ?string $methodName,
        string $testType,
        string $model,
        ?string $provider,
        ?string $customPath = null,
    ): string {
        // Cas A : Chemin personnalisé fourni via --spec=<customPath>
        if (null !== $customPath) {
            if (!file_exists($customPath)) {
                throw new \InvalidArgumentException(sprintf('Le fichier de spécification spécifié est introuvable : %s', $customPath));
            }

            return file_get_contents($customPath) ?: '';
        }

        // Cas B : Convention automatique (tests/Specs/<ClassName>Spec.md)
        $targetPath = $this->specPathResolver->resolve('tests/Specs', $shortClassName);

        if (!$targetPath->fileExists()) {
            $result = $this->generateAndSaveSpec(
                shortClassName: $shortClassName,
                classCode: $classCode,
                fqcn: $fqcn,
                methodName: $methodName,
                testType: $testType,
                model: $model,
                provider: $provider
            );

            return $result->markdownContent;
        }

        return file_get_contents($targetPath->mdFilePath) ?: '';
    }
}
