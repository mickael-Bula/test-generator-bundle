<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

use Mika\TestGeneratorBundle\Interceptor\Support\SourceSpan;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class CommentPolicy
{
    private const PRAGMA = '/@phpstan-|@psalm-|phpcs:|@codeCoverage|@noinspection|NOSONAR/i';
    private const MARKER = '/^\/\/\s*(?<m>ÉTANT DONNÉ|ETANT DONNE|QUAND|ALORS)(?![\p{L}])/u';

    public function __construct(private Parser $parser) {}

    /**
     * @return list<array{pos: int, length: int, line: int, replacement: ?string}> replacement null = suppression
     */
    public function violations(string $code): array
    {
        // Docblocks attachés à une classe, une méthode ou une fonction : interdits
        $attached = [];
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return []; // syntaxe invalide : traitée ailleurs
        }
        $nodes = (new NodeFinder())->find($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassLike
            || $n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Stmt\Function_);
        foreach ($nodes as $node) {
            if (null !== $doc = $node->getDocComment()) {
                $attached[$doc->getStartFilePos()] = true;
            }
        }

        $found = [];
        foreach (\PhpToken::tokenize($code) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT]) || 1 === preg_match(self::PRAGMA, $token->text)) {
                continue;
            }
            $item = ['pos' => $token->pos, 'length' => \strlen($token->text), 'line' => $token->line, 'replacement' => null];

            if ($token->is(T_DOC_COMMENT)) {
                if (isset($attached[$token->pos]) || !str_contains($token->text, '@var')) {
                    $found[] = $item;
                }
                continue;
            }

            if (1 === preg_match(self::MARKER, $token->text, $m)) {
                $canonical = match ($m['m']) {
                    'QUAND' => '// QUAND',
                    'ALORS' => '// ALORS',
                    default => '// ÉTANT DONNÉ',
                };
                if ($token->text !== $canonical) {
                    $found[] = ['replacement' => $canonical] + $item; // marqueur avec texte parasite
                }
                continue;
            }

            $found[] = $item;
        }

        return $found;
    }

    /**
     * @param list<array{pos: int, length: int, line: int, replacement: ?string}> $items issus de violations() sur le MÊME code
     */
    public function apply(string $code, array $items): string
    {
        usort($items, static fn (array $a, array $b): int => $b['pos'] <=> $a['pos']); // de la fin vers le début

        foreach ($items as $i) {
            $pos = $i['pos'];
            $end = $pos + $i['length'] - 1;

            if (null !== $i['replacement']) {
                $code = substr_replace($code, $i['replacement'], $pos, $i['length']);
                continue;
            }

            $newline = strrpos(substr($code, 0, $pos), "\n");
            $lineStart = false === $newline ? 0 : $newline + 1;

            if ('' === trim(substr($code, $lineStart, $pos - $lineStart))) {
                [$from, $length] = SourceSpan::lines($code, $pos, $end);   // commentaire seul sur sa ligne
            } else {
                $from = $pos;                                              // commentaire de fin de ligne
                while ($from > 0 && \in_array($code[$from - 1], [' ', "\t"], true)) {
                    --$from;
                }
                $length = $end - $from + 1;
            }
            $code = substr_replace($code, '', $from, $length);
        }

        return $code;
    }
}