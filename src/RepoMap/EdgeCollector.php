<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class EdgeCollector extends NodeVisitorAbstract
{
    public array $edges = [];
    private ?string $cur = null;
    private string $nodes = '';
    private array $uses = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->nodes = (string)$node->name;
        }

        if ($node instanceof Node\Stmt\UseUse) {
            $this->uses[$node->getAlias()->toString()] = $node->name->toString();
        }

        if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName) {
            $this->cur = $node->namespacedName->toString();
            $this->edges[$this->cur] ??= [];
        }

        if (!$this->cur) {
            return null;
        }

        if ($node instanceof Node\Name\FullyQualified) {
            $this->edges[$this->cur][] = $node->toString();
        }

        if ($doc = $node->getDocComment()) {
            preg_match_all('/@(?:param|return|var|throws|extends|implements)\s+([^\n]+)/', $doc->getText(), $m);
            preg_match_all('/\\\\?[A-Z]\w*(?:\\\\\w+)*/', implode(' ', $m[1]), $names);
            foreach ($names[0] as $short) {
                $first = strtok(ltrim($short, '\\'), '\\');
                $this->edges[$this->cur][] = $short[0] === '\\'
                    ? ltrim($short, '\\')
                    : ($this->uses[$first] ?? $this->nodes . '\\' . $short);
            }
        }
        return null;
    }
}