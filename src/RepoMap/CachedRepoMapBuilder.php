<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Finder\Finder;

class CachedRepoMapBuilder
{
    private const CACHE_KEY_MAP = 'repo_map_content';
    private const CACHE_KEY_HASH = 'repo_map_hash';

    public function __construct(
        private readonly RepoMapBuilder $repoMapBuilder,
        private readonly CacheItemPoolInterface $cache,
    ) {
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
        $currentHash = $this->calculateDirectoryHash($sourceDir);

        $cachedHashItem = $this->cache->getItem(self::CACHE_KEY_HASH);
        $cachedMapItem = $this->cache->getItem(self::CACHE_KEY_MAP);

        // Si le cache existe et que le hash du dossier n'a pas changé
        if ($cachedHashItem->isHit() && $cachedMapItem->isHit() && $cachedHashItem->get() === $currentHash) {
            return (string) $cachedMapItem->get();
        }

        // Sinon, on régénère le Repo-Map
        $newMap = $this->repoMapBuilder->buildMap($sourceDir);

        // Sauvegarde dans le cache
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
            $hashes[] = $file->getRelativePathname().':'.$file->getMTime();
        }

        // On trie pour garantir le même ordre d'itération.
        sort($hashes);

        return md5(implode('|', $hashes));
    }
}
