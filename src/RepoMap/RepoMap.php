<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

final class RepoMap
{
    /** @var array<string, array{file: string, edges: string[]}> */
    public array $classes = [];

    public function build(string $srcDir): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach (Finder::create()->files()->in($srcDir)->name('*.php') as $f) {
            $c = new EdgeCollector();
            $t = new NodeTraverser();
            $t->addVisitor(new NameResolver());
            $t->addVisitor($c);
            $t->traverse($parser->parse($f->getContents()));
            foreach ($c->edges as $fqcn => $edges) {
                $this->classes[$fqcn] = [
                    'file'  => $f->getRealPath(),
                    'edges' => array_values(array_unique(array_diff($edges, [$fqcn]))),
                ];
            }
        }
    }

    /** @return array<string,int> fqcn => profondeur */
    public function expand(array $seeds, int $depth): array
    {
        $seen = [];
        $queue = [];
        foreach ($seeds as $s) {
            if (isset($this->classes[$s])) { $seen[$s] = 0; $queue[] = $s; }
        }
        while ($queue) {
            $c = array_shift($queue);
            if ($seen[$c] >= $depth) continue;
            foreach ($this->classes[$c]['edges'] as $e) {
                if (isset($this->classes[$e]) && !isset($seen[$e])) {
                    $seen[$e] = $seen[$c] + 1;
                    $queue[] = $e;
                }
            }
        }
        return $seen;
    }
}
