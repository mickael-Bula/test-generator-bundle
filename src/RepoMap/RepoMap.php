<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

final class RepoMap
{
    /**
     * @var array<string, array{file: string, edges: string[]}>
     */
    public array $classes = [];

    public function build(string $srcDir): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach (Finder::create()->files()->in($srcDir)->name('*.php') as $file) {
            $collector = new EdgeCollector();
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($parser->parse($file->getContents()));
            foreach ($collector->edges as $fqcn => $edges) {
                $this->classes[$fqcn] = [
                    'file'  => $file->getRealPath(),
                    'edges' => array_values(array_unique(array_diff($edges, [$fqcn]))),
                ];
            }
        }
    }

    /**
     * @return array<string,int> fqcn => profondeur
     */
    public function expand(array $seeds, int $depth): array
    {
        $seen = [];
        $queue = [];
        foreach ($seeds as $seed) {
            if (isset($this->classes[$seed])) {
                $seen[$seed] = 0; $queue[] = $seed;
            }
        }
        while ($queue) {
            $collector = array_shift($queue);
            if ($seen[$collector] >= $depth) {
                continue;
            }
            foreach ($this->classes[$collector]['edges'] as $edge) {
                if (isset($this->classes[$edge]) && !isset($seen[$edge])) {
                    $seen[$edge] = $seen[$collector] + 1;
                    $queue[] = $edge;
                }
            }
        }
        return $seen;
    }
}
