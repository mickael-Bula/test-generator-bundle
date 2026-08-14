<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

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
     * @return array{0: string, 1: string, 2: string} Un tuple contenant :
     *                                                - 0: Le namespace cible de la classe de test
     *                                                - 1: Le chemin absolu du dossier de destination du test
     *                                                - 2: Le chemin absolu complet vers le fichier de test (.php)
     *
     * @throws \ReflectionException si la classe fournie via $fqcn n'existe pas ou ne peut pas être inspectée
     */
    public function resolve(string $fqcn, string $shortClassName, TestType|string $testType = TestType::UNIT): array
    {
        // Conversion en Enum si une chaîne de caractères est transmise
        $typeEnum = $testType instanceof TestType ? $testType : TestType::from($testType);

        // 1. Détection du nom de sous-dossier ("Unit" ou "Functional")
        $subFolder = TestType::FUNCTIONAL === $typeEnum ? 'Functional' : 'Unit';

        // 2. Détection de l'emplacement réel de la classe à tester via ReflectionClass
        $reflection = new \ReflectionClass($fqcn);
        $classFilePath = $reflection->getFileName();

        if (false === $classFilePath) {
            throw new \InvalidArgumentException(sprintf('Impossible de localiser le fichier pour la classe "%s".', $fqcn));
        }

        $classFilePath = str_replace('\\', '/', $classFilePath);

        // 3. Extraction du namespace d'origine
        $originNamespace = $reflection->getNamespaceName();

        // 4. Calcul du namespace de test cible (ex : App\Tests\Unit\Service)
        $targetNamespace = $this->getTargetNamespace($originNamespace, $subFolder);

        // 5. Calcul du dossier cible (ex : /path/to/project/tests/Unit/Service)
        $finalDisplayDir = $this->getTargetDirectory($classFilePath, $originNamespace, $targetNamespace, $subFolder);

        $finalAbsoluteFilePath = sprintf('%s/%sTest.php', rtrim($finalDisplayDir, '/'), $shortClassName);

        return [$targetNamespace, $finalDisplayDir, $finalAbsoluteFilePath];
    }

    /**
     * Génère le namespace de test correspondant au namespace d'origine selon les normes PSR-4
     * en injectant le sous-dossier de type de test (Unit ou Functional).
     *
     * Exemple pour App : "App\Service" → "App\Tests\Unit\Service"
     * Exemple pour un Bundle : "Mika\TestGeneratorBundle\Service" → "Mika\TestGeneratorBundle\Tests\Unit\Service"
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

        // Pour un bundle, on conserve Vendor\PackageName puis on ajoute \Tests\<SubFolder>
        $parts = explode('\\', $originNamespace);

        if (count($parts) >= 2) {
            $vendorAndPackage = array_shift($parts) . '\\' . array_shift($parts);
            $remainingPath = !empty($parts) ? '\\' . implode('\\', $parts) : '';

            return $vendorAndPackage . '\\Tests\\' . $subFolder . $remainingPath;
        }

        return $originNamespace . '\\Tests\\' . $subFolder;
    }

    /**
     * Calcule le dossier racine de destination pour enregistrer le fichier de test.
     *
     * Si la classe se trouve dans un dossier '/src/', bascule vers le dossier '/tests/<subFolder>' parent direct
     * (qu'il s'agisse du projet hôte ou d'un bundle lié via symlink vendor). Sinon, bascule sur le dossier
     * '/tests/<subFolder>' du projet hôte en fallback.
     *
     * @param string $classFilePath   chemin absolu du fichier PHP de la classe source
     * @param string $originNamespace namespace d'origine de la classe source
     * @param string $targetNamespace namespace cible calculé pour le test
     * @param string $subFolder       sous-dossier cible ("Unit" ou "Functional")
     *
     * @return string chemin absolu du répertoire où le fichier de test doit être créé
     */
    private function getTargetDirectory(
        string $classFilePath,
        string $originNamespace,
        string $targetNamespace,
        string $subFolder,
    ): string {
        if (str_contains($classFilePath, '/src/')) {
            // Remplace /src/ par /tests/<subFolder> dans le chemin d'accès absolu
            $baseDir = preg_replace('#/src/(.+)$#', '/tests/' . $subFolder, $classFilePath);

            // Construit le sous-dossier relatif basé sur la structure des namespaces
            $relativeSubFolder = str_replace(
                ['\\', '/'],
                '/',
                substr($originNamespace, strpos($originNamespace, '\\') ?: 0)
            );
            $relativeSubFolder = trim($relativeSubFolder, '/');

            // Retire le deuxième segment du namespace si c'est un bundle (ex : retire TestGeneratorBundle)
            $subFolderParts = explode('/', $relativeSubFolder);
            if (count($subFolderParts) > 1 && !str_starts_with($originNamespace, 'App\\')) {
                array_shift($subFolderParts);
            }

            $subPath = implode('/', array_filter($subFolderParts));

            return !empty($subPath) ? sprintf('%s/%s', $baseDir, $subPath) : $baseDir;
        }

        // Fallback générique basé sur le projet hôte si la classe n'est pas sous un dossier /src/
        $normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $relativeDir = str_replace(['App\\Tests\\', '\\'], ['', '/'], $targetNamespace);

        return sprintf('%s/tests/%s', $normalizedProjectDir, trim($relativeDir, '/'));
    }
}
