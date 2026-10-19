<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

/**
 * Lit le corps d'une méthode, relève chaque setX(expression) appliqué à l’objet retourné (et les arguments de new),
 * retrouve le getter correspondant par réflexion, puis exprime l’expression avec les variables du test.
 */
final readonly class ReturnedStateAnalyzer
{
    private const VOLATILE = '/\b(?:DateTime|DateTimeImmutable|time|date|microtime|uniqid|rand|mt_rand|random_int|random_bytes)\b/';

    public function __construct(private Parser $parser)
    {
    }

    /**
     * Ce que le code d'une méthode affecte à l'objet qu'il retourne, exprimé avec les arguments du test.
     *
     * @param array<string, string> $args nom du paramètre de la méthode => expression passée par le test
     *
     * @return list<array{getter: string, expression: string, volatile: bool}>
     * @throws \ReflectionException
     */
    public function describe(\ReflectionMethod $method, array $args): array
    {
        $type = $method->getReturnType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
            return [];
        }
        $class = new \ReflectionClass($type->getName());
        $file = $method->getFileName();
        if (false === $file) {
            return [];
        }
        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver()); // noms complets : le LLM n'a pas à les deviner
        $stmts = $traverser->traverse($stmts);

        $node = (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod
            && 0 === strcasecmp($n->name->toString(), $method->getName()));
        $body = $node instanceof Node\Stmt\ClassMethod ? ($node->stmts ?? []) : [];
        $last = [] === $body ? null : $body[array_key_last($body)];
        if (!$last instanceof Node\Stmt\Return_ || null === $last->expr) {
            return [];
        }

        // `return new X(...)`, ou `return $var;` avec `$var = new X(...)` plus haut
        $new = $last->expr instanceof Node\Expr\New_ ? $last->expr : null;
        $var = $last->expr instanceof Node\Expr\Variable && \is_string($last->expr->name) ? $last->expr->name : null;
        if (null !== $var) {
            foreach ($body as $statement) {
                if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                    && $statement->expr->var instanceof Node\Expr\Variable && $statement->expr->var->name === $var
                    && $statement->expr->expr instanceof Node\Expr\New_) {
                    $new ??= $statement->expr->expr;
                }
            }
        }
        if (null === $new) {
            return [];
        }

        $assignments = []; // [propriété, expression]
        $ctor = $class->getConstructor();
        foreach ($new->args as $i => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                break;
            }
            $name = $arg->name?->toString() ?? ((($ctor?->getParameters() ?? [])[$i] ?? null)?->getName());
            if (null !== $name) {
                $assignments[] = [$name, $arg->value];
            }
        }
        if (null !== $var) {
            foreach ($body as $statement) {
                $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
                if ($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\Variable && $call->var->name === $var
                    && $call->name instanceof Node\Identifier && 1 === preg_match('/^(?:set|add)([A-Z]\w*)$/', $call->name->toString(), $m)
                    && 1 === \count($call->args) && $call->args[0] instanceof Node\Arg) {
                    $assignments[] = [lcfirst($m[1]), $call->args[0]->value];
                }
            }
        }

        // Variables que le test peut nommer : celles des expressions qu'il passe à la méthode
        $allowed = [];
        foreach ($args as $value) {
            preg_match_all('/\$(\w+)/', $value, $v);
            $allowed += array_flip($v[1]);
        }
        $names = implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), array_keys($args)));
        $printer = new Standard();

        $entries = [];
        foreach ($assignments as [$property, $expr]) {
            $getter = $this->getterFor($class, $property);
            $text = $printer->prettyPrintExpr($expr);
            if (null === $getter || str_contains($text, '$this')) {
                continue; // état interne du SUT : le test ne peut pas le nommer
            }
            $volatile = 1 === preg_match(self::VOLATILE, $text);

            if ('' !== $names) { // une seule passe : un paramètre ne peut pas être remplacé deux fois
                $text = (string) preg_replace_callback('/\$(' . $names . ')\b/', static fn (array $m): string => $args[$m[1]], $text);
            }
            preg_match_all('/\$(\w+)/', $text, $left);
            if ([] !== array_diff($left[1], array_keys($allowed))) {
                continue; // dépend d'une variable locale du SUT (ex. un calcul intermédiaire)
            }

            $entries[$getter] = ['getter' => $getter, 'expression' => $text, 'volatile' => $volatile];
        }

        return array_values($entries);
    }

    /**
     * `setIsCore` => `isCore`, `setQuantity` => `getQuantity` ; null si aucun accesseur public ne correspond.
     */
    private function getterFor(\ReflectionClass $class, string $property): ?string
    {
        $base = ucfirst($property);
        $candidates = ['get' . $base, 'is' . $base, 'has' . $base];
        if (str_starts_with($base, 'Is')) {
            $candidates[] = 'is' . substr($base, 2);
        }
        foreach ($candidates as $name) {
            if ($class->hasMethod($name) && $class->getMethod($name)->isPublic()) {
                return $name;
            }
        }

        return null;
    }
}