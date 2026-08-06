<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

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
     * @param string $fqcn           Nom complet de la classe à tester
     * @param string $shortClassName Nom court de la classe
     *
     * @return array{0: string, 1: string, 2: string} Un tuple contenant :
     *                                                - 0: Le namespace cible de la classe de test
     *                                                - 1: Le chemin absolu du dossier de destination du test
     *                                                - 2: Le chemin absolu complet vers le fichier de test (.php)
     *
     * @throws \ReflectionException      si la classe fournie via $fqcn n'existe pas ou ne peut pas être inspectée
     * @throws \InvalidArgumentException si le chemin d'accès au fichier source de la classe ne peut pas être déterminé
     */
    public function resolve(string $fqcn, string $shortClassName): array
    {
        // 1. Détection de l'emplacement réel de la classe à tester via ReflectionClass
        $reflection = new \ReflectionClass($fqcn);
        $classFilePath = $reflection->getFileName();

        if (false === $classFilePath) {
            throw new \InvalidArgumentException(sprintf('Impossible de localiser le fichier pour la classe "%s".', $fqcn));
        }

        $classFilePath = str_replace('\\', '/', $classFilePath);

        // 2. Extraction du namespace d'origine
        $originNamespace = $reflection->getNamespaceName();

        // 3. Calcul du namespace de test cible
        $targetNamespace = $this->getTargetNamespace($originNamespace);

        // 4. Calcul du dossier cible
        $finalDisplayDir = $this->getTargetDirectory($classFilePath, $originNamespace, $targetNamespace);

        $finalAbsoluteFilePath = sprintf('%s/%sTest.php', rtrim($finalDisplayDir, '/'), $shortClassName);

        return [$targetNamespace, $finalDisplayDir, $finalAbsoluteFilePath];
    }

    /**
     * Génère le namespace de test correspondant au namespace d'origine selon les normes PSR-4.
     *
     * Exemple pour App : "App\Service" → "App\Tests\Service"
     * Exemple pour un Bundle : "Mika\TestGeneratorBundle\Service" → "Mika\TestGeneratorBundle\Tests\Service"
     *
     * @param string $originNamespace namespace PHP de la classe source
     *
     * @return string namespace fully-qualified pour la classe de test
     */
    private function getTargetNamespace(string $originNamespace): string
    {
        if (str_starts_with($originNamespace, 'App\\')) {
            $targetNamespace = str_replace('App\\', 'App\\Tests\\', $originNamespace);
        } else {
            // Pour un bundle, on conserve Vendor\PackageName puis on ajoute \Tests (ex : Mika\TestGeneratorBundle\Tests\Service)
            $parts = explode('\\', $originNamespace);

            if (count($parts) >= 2) {
                $vendorAndPackage = array_shift($parts) . '\\' . array_shift($parts);
                $remainingPath = !empty($parts) ? '\\' . implode('\\', $parts) : '';
                $targetNamespace = $vendorAndPackage . '\\Tests' . $remainingPath;
            } else {
                $targetNamespace = $originNamespace . '\\Tests';
            }
        }

        return $targetNamespace;
    }

    /**
     * Calcule le dossier racine de destination pour enregistrer le fichier de test.
     *
     * Si la classe se trouve dans un dossier '/src/', bascule vers le dossier '/tests/' parent direct
     * (qu'il s'agisse du projet hôte ou d'un bundle lié via symlink vendor). Sinon, bascule sur le dossier
     * '/tests/' du projet hôte en fallback.
     *
     * @param string $classFilePath   chemin absolu du fichier PHP de la classe source
     * @param string $originNamespace namespace d'origine de la classe source
     * @param string $targetNamespace namespace cible calculé pour le test
     *
     * @return string chemin absolu du répertoire où le fichier de test doit être créé
     */
    private function getTargetDirectory(string $classFilePath, string $originNamespace, string $targetNamespace): string
    {
        if (str_contains($classFilePath, '/src/')) {
            // Remplace /src/ par /tests/ dans le chemin d'accès absolu
            $baseDir = preg_replace('#/src/(.+)$#', '/tests', $classFilePath);

            // Construit le sous-dossier relatif basé sur la structure des namespaces
            $relativeSubFolder = str_replace(
                ['\\', '/'],
                '/', substr($originNamespace, strpos($originNamespace, '\\') ?: 0)
            );
            $relativeSubFolder = trim($relativeSubFolder, '/');

            // Retire le deuxième segment du namespace si c'est un bundle (ex : retire TestGeneratorBundle)
            $subFolderParts = explode('/', $relativeSubFolder);
            if (count($subFolderParts) > 1 && !str_starts_with($originNamespace, 'App\\')) {
                array_shift($subFolderParts);
            }

            $subPath = implode('/', array_filter($subFolderParts));
            $finalDisplayDir = !empty($subPath) ? sprintf('%s/%s', $baseDir, $subPath) : $baseDir;
        } else {
            // Fallback générique basé sur le projet hôte si la classe n'est pas sous un dossier /src/
            $normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
            $subFolder = str_replace(['App\\Tests\\', '\\'], ['', '/'], $targetNamespace);
            $finalDisplayDir = sprintf('%s/tests/%s', $normalizedProjectDir, trim($subFolder, '/'));
        }

        return $finalDisplayDir;
    }
}
