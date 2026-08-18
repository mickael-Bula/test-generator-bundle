<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

class CachedRepoMapBuilder
{
    private const CACHE_KEY_PREFIX = 'repo_map_';

    public function __construct(
        private readonly RepoMapBuilder $repoMapBuilder,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * Point d'entrée appelé par le PromptBuilder avec le chemin du fichier cible ($filePath).
     *
     * @throws InvalidArgumentException
     */
    public function buildMapForFile(?string $filePath = null): string
    {
        $sourceDir = $this->resolveSourceDir($filePath);

        return $this->buildMap($sourceDir);
    }

    /**
     * Résout le répertoire source à scanner en fonction du fichier cible.
     */
    private function resolveSourceDir(?string $filePath): string
    {
        if (null === $filePath || !file_exists($filePath)) {
            return $this->projectDir . '/src';
        }

        // Remonte au `composer.json` le plus proche
        $packageRootDir = $this->findPackageRootDir($filePath);

        // Si un sous-dossier src/ existe dans ce package, on scanne src/, sinon la racine du package
        return file_exists($packageRootDir . '/src')
            ? $packageRootDir . '/src'
            : $packageRootDir;
    }

    /**
     * Remonte les dossiers parents pour trouver le composer.json le plus proche.
     */
    private function findPackageRootDir(string $filePath): string
    {
        $dir = dirname($filePath);

        while ($dir !== dirname($dir) && mb_strlen($dir) >= mb_strlen($this->projectDir)) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return $this->projectDir . '/src';
    }

    /**
     * Méthode qui calcule un hash combiné des chemins + dates de modification (mtime) de tous les fichiers PHP sous src/.
     * Si le hash calculé correspond à celui stocké en cache, retourner le texte du Repo-Map en cache.
     * Sinon, réexécute la génération, stocke le nouveau résultat et met à jour le hash.
     *
     * @throws InvalidArgumentException
     */
    public function buildMap(string $sourceDir): string
    {
        if (!is_dir($sourceDir)) {
            return '';
        }

        // 1. Clé de cache unique pour ce dossier spécifique (basée sur md5 du chemin)
        $dirKey = md5($sourceDir);
        $cacheKeyMap = self::CACHE_KEY_PREFIX . 'content_' . $dirKey;
        $cacheKeyHash = self::CACHE_KEY_PREFIX . 'hash_' . $dirKey;

        // 2. Calcul du hash dynamique du dossier
        $currentHash = $this->calculateDirectoryHash($sourceDir);

        $cachedHashItem = $this->cache->getItem($cacheKeyHash);
        $cachedMapItem = $this->cache->getItem($cacheKeyMap);

        // 3. Si le cache existe et que le hash du dossier n'a pas changé
        if ($cachedHashItem->isHit() && $cachedMapItem->isHit() && $cachedHashItem->get() === $currentHash) {
            return (string) $cachedMapItem->get();
        }

        // 4. Sinon, on régénère le Repo-Map via le vrai RepoMapBuilder
        $newMap = $this->repoMapBuilder->buildMap($sourceDir);

        // 5. Sauvegarde dans le cache
        $cachedHashItem->set($currentHash);
        $cachedMapItem->set($newMap);

        $this->cache->save($cachedHashItem);
        $this->cache->save($cachedMapItem);

        return $newMap;
    }

    /**
     * Calcule un hash unique basé sur les chemins et dates de modification (mtime) de tous les fichiers .php.
     */
    private function calculateDirectoryHash(string $sourceDir): string
    {
        if (!is_dir($sourceDir)) {
            return '';
        }

        $finder = new Finder();
        $finder->files()->in($sourceDir)->name('*.php');

        $hashes = [];
        foreach ($finder as $file) {
            // Combine le chemin relatif et le timestamp de dernière modification
            $hashes[] = $file->getRelativePathname() . ':' . $file->getMTime();
        }

        // On trie pour garantir le même ordre d'itération.
        sort($hashes);

        return md5(implode('|', $hashes));
    }
}
