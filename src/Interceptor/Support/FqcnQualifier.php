<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class FqcnQualifier
{
    public function __construct(private Parser $parser) {}

    /** Remplace le nom introuvable par le FQCN réel (avec antislash initial). */
    public function qualify(string $code, string $missing, string $real, string $testNamespace): string
    {
        $short = basename(str_replace('\\', '/', $missing));
        $missingNamespace = substr($missing, 0, (int) strrpos($missing, '\\'));
        $unimported = $missingNamespace === $testNamespace; // nom court non importé, résolu dans le namespace du test

        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return $code;
        }

        /** @var list<Name> $targets */
        $targets = (new NodeFinder())->find($stmts, static fn (Node $n): bool => $n instanceof Name
            && null !== $n->getAttribute('startFilePos')
            && ($n instanceof Name\FullyQualified
                ? $n->toString() === $missing                                         // namespace inventé : \Faux\X
                : $unimported && $n->isUnqualified() && $n->toString() === $short));  // X sans import

        // De la fin vers le début : les positions restent valides après chaque remplacement.
        usort($targets, static fn (Name $a, Name $b): int => $b->getStartFilePos() <=> $a->getStartFilePos());

        foreach ($targets as $name) {
            $code = substr_replace(
                $code,
                '\\' . $real,
                $name->getStartFilePos(),
                $name->getEndFilePos() - $name->getStartFilePos() + 1,
            );
        }

        return $code;
    }
}