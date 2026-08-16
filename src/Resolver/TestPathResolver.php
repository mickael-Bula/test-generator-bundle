<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Mika\TestGeneratorBundle\Dto\TestTargetPath;
use Mika\TestGeneratorBundle\Enum\TestType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class TestPathResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    /**
     * Calcule le namespace cible ainsi que les chemins absolus et relatifs du fichier de test généré.
     *
     * @param string          $fqcn           Nom complet de la classe à tester
     * @param string          $shortClassName Nom court de la classe
     * @param TestType|string $testType       Type de test (Unit ou Functional)
     *
     * @throws \ReflectionException si la classe fournie via $fqcn n'existe pas ou ne peut pas être inspectée
     */
    public function resolve(string $fqcn, string $shortClassName, TestType|string $testType = TestType::UNIT): TestTargetPath
    {
        $typeEnum = $testType instanceof TestType ? $testType : TestType::from($testType);
        $subFolder = TestType::FUNCTIONAL === $typeEnum ? 'Functional' : 'Unit';

        $reflection = new \ReflectionClass($fqcn);
        $originNamespace = $reflection->getNamespaceName();

        $targetNamespace = $this->getTargetNamespace($originNamespace, $subFolder);
        $finalDisplayDir = $this->getTargetDirectory($originNamespace, $subFolder);

        $finalAbsoluteFilePath = sprintf('%s/%sTest.php', rtrim($finalDisplayDir, '/'), $shortClassName);

        return new TestTargetPath(
            targetNamespace: $targetNamespace,
            targetDirectory: $finalDisplayDir,
            filePath: $finalAbsoluteFilePath,
        );
    }

    /**
     * Génère le namespace de test correspondant au namespace d'origine selon les normes PSR-4,
     * en le rattachant systématiquement à la racine de l'application hôte (App\Tests\...).
     *
     * Exemple pour App : "App\Service" → "App\Tests\Unit\Service"
     * Exemple pour un Bundle : "Mika\TestGeneratorBundle\Command" → "App\Tests\Unit\Command"
     *
     * @param string $originNamespace namespace PHP de la classe source
     * @param string $subFolder       sous-dossier cible ("Unit" ou "Functional")
     *
     * @return string namespace fully-qualified pour la classe de test
     */
    private function getTargetNamespace(string $originNamespace, string $subFolder): string
    {
        if (str_starts_with($originNamespace, 'App\\')) {
            return str_replace('App\\', 'App\\Tests\\' . $subFolder . '\\', $originNamespace);
        }

        $parts = explode('\\', $originNamespace);
        $filteredParts = $this->filterNamespaceParts($parts);

        $relativePath = !empty($filteredParts) ? '\\' . implode('\\', $filteredParts) : '';

        return 'App\\Tests\\' . $subFolder . $relativePath;
    }

    /**
     * Calcule le dossier racine de destination pour enregistrer le fichier de test.
     *
     * Le chemin est toujours ancré sur le répertoire du projet hôte (%kernel.project_dir%).
     * Pour les classes hors "App\", les préfixes de vendor/bundle sont nettoyés pour
     * faire correspondre l'arborescence directement sous "tests/<subFolder>/".
     *
     * Exemple pour App : "App\Service" → "/path/to/project/tests/Unit/Service"
     * Exemple pour un Bundle : "Mika\TestGeneratorBundle\Command" → "/path/to/project/tests/Unit/Command"
     *
     * @param string $originNamespace namespace d'origine de la classe source
     * @param string $subFolder       sous-dossier cible ("Unit" ou "Functional")
     *
     * @return string chemin absolu du répertoire où le fichier de test doit être créé
     */
    private function getTargetDirectory(string $originNamespace, string $subFolder): string
    {
        $normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');

        if (str_starts_with($originNamespace, 'App\\')) {
            $relativeDir = str_replace(['App\\', '\\'], ['', '/'], $originNamespace);

            return sprintf('%s/tests/%s/%s', $normalizedProjectDir, $subFolder, trim($relativeDir, '/'));
        }

        $parts = explode('\\', $originNamespace);
        $filteredParts = $this->filterNamespaceParts($parts);

        $relativeSubPath = implode('/', $filteredParts);

        return sprintf(
            '%s/tests/%s/%s',
            $normalizedProjectDir,
            $subFolder,
            trim($relativeSubPath, '/')
        );
    }

    /**
     * Filtre les préfixes de namespace du vendor et du bundle (App, Mika, TestGeneratorBundle).
     *
     * @param array<string> $parts
     *
     * @return array<string>
     */
    private function filterNamespaceParts(array $parts): array
    {
        return array_values(array_filter($parts, static function (string $part) {
            return !in_array($part, ['App', 'Mika', 'TestGeneratorBundle'], true);
        }));
    }
}
