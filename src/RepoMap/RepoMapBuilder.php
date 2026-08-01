<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

class RepoMapBuilder
{
    public function buildMap(string $sourceDir): string
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $finder = new Finder();
        $finder->files()
            ->in($sourceDir)
            ->name('*.php');

        $mapOutput = [];

        foreach ($finder as $file) {
            try {
                $stmts = $parser->parse($file->getContents());
                if (null === $stmts) {
                    continue;
                }

                $traverser = new NodeTraverser();

                // Ajoute le resolver de noms
                $traverser->addVisitor(new NameResolver());

                $visitor = new RepoMapVisitor();
                $traverser->addVisitor($visitor);

                $traverser->traverse($stmts);

                foreach ($visitor->getMap() as $class => $methods) {
                    if (empty($methods)) {
                        continue;
                    }
                    $mapOutput[] = $class;
                    foreach ($methods as $method) {
                        $mapOutput[] = $method;
                    }
                    $mapOutput[] = ''; // Ligne vide pour aérer
                }
            } catch (\Throwable $e) {
                // Ignore les fichiers malformés
                continue;
            }
        }

        return implode("\n", $mapOutput);
    }
}
