<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

/**
 * Relève les messages lancés par le SUT
 */
final readonly class ThrownMessages
{
    private const PLACEHOLDER = '/(%(?:%|(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]))/';

    public function __construct(private Parser $parser) {}

    /**
     * Messages des exceptions créées dans les méthodes du SUT (`new XException(<message>)`) : littéral, `sprintf`,
     * concaténation ou chaîne interpolée. Les parties dynamiques deviennent `.*?` dans l'expression régulière.
     *
     * @return list<array{class: string, method: string, line: int, display: string, regex: string, stable: string}>
     */
    public function of(\ReflectionClass $sut): array
    {
        $file = $sut->getFileName();
        if (false === $file) {
            return [];
        }
        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $finder = new NodeFinder();
        $sites = [];
        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $method) {
            foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\New_::class) as $new) {
                if (!$new->class instanceof Node\Name || !$this->isThrowable($new->class->toString())) {
                    continue;
                }
                $arg = $new->args[0] ?? null;
                $segments = $arg instanceof Node\Arg ? $this->segments($arg->value) : null;
                if (null === $segments || [] === array_filter($segments, static fn (?string $s): bool => null !== $s && '' !== trim($s))) {
                    continue; // message entièrement dynamique : rien à comparer
                }
                $sites[] = $this->site($new->class->toString(), $method->name->toString(), $new->getStartLine(), $segments);
            }
        }

        return $sites;
    }

    private function isThrowable(string $class): bool
    {
        return is_a($class, \Throwable::class, true) || str_ends_with($class, 'Exception') || str_ends_with($class, 'Error');
    }

    /**
     * @return list<string|null>|null littéraux, et null pour chaque partie dynamique ; null si la forme n'est pas gérée
     */
    private function segments(Node\Expr $e): ?array
    {
        if ($e instanceof Node\Scalar\String_) {
            return [$e->value];
        }
        if ($e instanceof Node\Expr\BinaryOp\Concat) {
            return [...($this->segments($e->left) ?? [null]), ...($this->segments($e->right) ?? [null])];
        }
        if ($e instanceof Node\Scalar\InterpolatedString) {
            return array_map(static fn (Node $part): ?string => $part instanceof Node\InterpolatedStringPart ? $part->value : null, $e->parts);
        }
        if ($e instanceof Node\Expr\FuncCall && $e->name instanceof Node\Name && 'sprintf' === strtolower($e->name->toString())) {
            $first = $e->args[0] ?? null;
            if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                $out = [];
                foreach (preg_split(self::PLACEHOLDER, $first->value->value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
                    $out[] = '%%' === $piece ? '%' : (1 === preg_match(self::PLACEHOLDER, $piece) ? null : $piece);
                }

                return $out;
            }
        }

        return null;
    }

    /**
     * @param list<string|null> $segments
     */
    private function site(string $class, string $method, int $line, array $segments): array
    {
        $merged = []; // littéraux adjacents concaténés, parties dynamiques adjacentes fusionnées
        foreach ($segments as $s) {
            $last = array_key_last($merged);
            if (null !== $last && (null === $s) === (null === $merged[$last])) {
                $merged[$last] = null === $s ? null : $merged[$last] . $s;
                continue;
            }
            $merged[] = $s;
        }

        $stable = '';
        foreach ($merged as $s) {
            if (null !== $s && \strlen(trim($s)) > \strlen($stable)) {
                $stable = trim($s);
            }
        }

        return [
            'class' => $class,
            'method' => $method,
            'line' => $line,
            'display' => implode('', array_map(static fn (?string $s): string => $s ?? '…', $merged)),
            'regex' => '/^' . implode('', array_map(static fn (?string $s): string => null === $s ? '.*?' : preg_quote($s, '/'), $merged)) . '$/su',
            'stable' => $stable,
        ];
    }
}