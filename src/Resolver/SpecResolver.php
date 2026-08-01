<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

readonly class SpecResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    /**
     * Tente de lire le contenu de la spec depuis un fichier s'il existe,
     * sinon retourne la chaîne brute fournie.
     */
    public function resolve(string|bool|null $specOption, string $shortClassName, ?SymfonyStyle $io = null): ?string
    {
        // Si `--spec` ou `-s` n'a pas été saisie
        if (false === $specOption) {
            return null;
        }
        // Si `--spec` ou `-s` a été passé SANS valeur ($specOption === null), on recherche un fichier <ClassName>Spec.md
        if (null === $specOption) {
            $conventionName = $shortClassName . 'Spec.md';
            $content = $this->resolveByFinderSearch($conventionName, $conventionName, $io);

            if (null === $content && null !== $io) {
                $io->warning(sprintf(
                    'Option --spec présente sans valeur, mais aucun fichier "%s" n\'a été trouvé dans le projet.',
                    $conventionName
                ));
            }

            return $content;
        }

        if ('' === trim($specOption)) {
            return null;
        }

        // Normalisation des séparateurs de dossier (Windows vs Linux)
        $normalizedOption = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $specOption);

        // Chaîne d'essai des différentes stratégies
        $content = $this->resolveByDirectPath($normalizedOption)
            ?? $this->resolveByRelativeProjectPath($normalizedOption)
            ?? $this->resolveByFinderSearch($normalizedOption, $specOption, $io);

        if (null !== $content) {
            return $content;
        }

        // Si aucun fichier n'a été trouvé, on émet un avertissement si l'entrée ressemblait à un fichier
        $this->warnIfLookedLikeFile($specOption, $normalizedOption, $io);

        // Traitement de l'option comme consigne de texte brute (ex : 'Traite uniquement la première méthode').
        return $specOption;
    }

    /**
     * 1. Recherche par chemin absolu ou relatif au dossier d'exécution direct.
     */
    private function resolveByDirectPath(string $normalizedOption): ?string
    {
        // Si le chemin peut être résolu directement (chemin absolu ou relatif au dossier d'exécution).
        if (file_exists($normalizedOption) && is_file($normalizedOption)) {
            return file_get_contents($normalizedOption) ?: null;
        }

        // Si le fichier direct n'existe pas, tente d'ajouter l'extension .md au nom de fichier
        if (file_exists($normalizedOption . '.md') && is_file($normalizedOption . '.md')) {
            return file_get_contents($normalizedOption . '.md') ?: null;
        }

        return null;
    }

    /**
     * 2. Recherche par chemin relatif à la racine du projet (ex : tests/Specs/my_spec.md).
     */
    private function resolveByRelativeProjectPath(string $normalizedOption): ?string
    {
        $relativePath = $this->projectDir . DIRECTORY_SEPARATOR . ltrim($normalizedOption, '/\\');

        if (file_exists($relativePath) && is_file($relativePath)) {
            return file_get_contents($relativePath) ?: null;
        }

        // Si non trouvé, on tente d'ajouter l'extension .md au chemin relatif
        if (file_exists($relativePath . '.md') && is_file($relativePath . '.md')) {
            return file_get_contents($relativePath . '.md') ?: null;
        }

        return null;
    }

    /**
     * 3. Recherche automatique par nom de fichier dans le projet (ex : VatCalculatorSpec.md).
     */
    private function resolveByFinderSearch(string $normalizedOption, string $specOption, ?SymfonyStyle $io): ?string
    {
        $fileName = basename($normalizedOption);

        // Si le nom ne finit pas par .md, on l'ajoute pour la recherche Finder
        if (!str_ends_with(mb_strtolower($fileName), '.md')) {
            $fileName .= '.md';
        }

        // On cherche dans tout le projet en ignorant les dossiers système/dépendances.
        $finder = new Finder();
        $finder->files()
            ->in($this->projectDir)
            ->exclude(['vendor', 'var', 'node_modules', '.git'])
            ->name($fileName);

        if (!$finder->hasResults()) {
            return null;
        }

        $files = iterator_to_array($finder);

        // S'il y en a exactement un
        if (1 === \count($files)) {
            /** @var SplFileInfo $foundFile */
            $foundFile = reset($files);

            return $foundFile->getContents() ?: null;
        }

        // S'il y a une ambiguïté (plusieurs fichiers avec le même nom).
        if (null !== $io && \count($files) > 1) {
            $io->warning(sprintf(
                'Plusieurs fichiers de spécification nommés "%s" ont été trouvés dans le projet. '
                . 'Veuillez préciser le chemin relatif complet.',
                $fileName
            ));
        }

        // Si ce n'est pas un fichier existant, on traite la chaîne directement comme une consigne textuelle.
        return $specOption;
    }

    /**
     * Avertit l'utilisateur si la saisie ressemblait à un nom/chemin de fichier, mais n'a pas été trouvée.
     */
    private function warnIfLookedLikeFile(string $specOption, string $normalizedOption, ?SymfonyStyle $io): void
    {
        if (null === $io) {
            return;
        }

        $looksLikeFile = str_contains($specOption, '.md')
            || str_contains($specOption, '/')
            || str_contains($specOption, '\\')
            || !str_contains($specOption, ' ');

        if ($looksLikeFile) {
            $relativePath = $this->projectDir . DIRECTORY_SEPARATOR . ltrim($normalizedOption, '/\\');

            $io->warning(sprintf(
                'Fichier de spécification non trouvé (recherché aux emplacements : "%s" ou via Finder). '
                . 'La valeur sera traitée comme du texte brut.',
                $relativePath
            ));
        }
    }
}
