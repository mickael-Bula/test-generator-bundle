<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\RepoMap\RepoMap;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class RepoMapProvider
{
    private ?RepoMap $map = null;

    public function __construct(
        private readonly string $srcDir,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function get(): RepoMap
    {
        return $this->map ??= $this->cache->get(
            'test_generator.repo_map.' . $this->fingerprint(),
            function (ItemInterface $item): RepoMap {
                $item->expiresAfter(86400);
                $map = new RepoMap();
                $map->build($this->srcDir);

                return $map;
            },
        );
    }

    /** La clé change dès qu'un fichier de src/ est modifié, ajouté ou supprimé. */
    private function fingerprint(): string
    {
        $h = '';
        foreach (Finder::create()->files()->in($this->srcDir)->name('*.php') as $f) {
            $h .= $f->getRealPath() . $f->getMTime();
        }

        return md5($h);
    }
}