<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Correcteur qui s’applique seulement si l’argument fautif est une variable simple jamais affectée dans la méthode de test,
 * et si le type attendu est une classe instanciable sans argument obligatoire.
 *
 * @noinspection PhpUnused
 */
#[AsTaggedItem(priority: 70)]
final readonly class UndefinedVariableArgumentPreFixer implements TestPreFixerInterface
{
    // language=RegExp
    private const PATTERN = '/TypeError: (?<class>[\w\\\\]+)::(?<method>\w+)\(\): Argument #(?<pos>\d+) \(\$(?<param>\w+)\) must be of type (?<expected>[^,\r\n]+), null given, called in \S+Test\.php on line (?<line>\d+)/';

    public function __construct(
        private Parser $parser,
        private CallArgumentLocator $locator,
    ) {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $errors, PREG_SET_ORDER);
        if ([] === $errors) {
            return null;
        }

        try {
            $stmts = $this->parser->parse($testCode) ?? [];
        } catch (Error) {
            return null;
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $finder = new NodeFinder();
        $inserts = []; // position => texte
        $done = [];

        foreach ($errors as $e) {
            $line = (int) $e['line'];
            $arg = $this->locator->find($stmts, $e['class'], $e['method'], $line, (int) $e['pos'], $e['param']);
            if (!$arg instanceof Node\Expr\Variable || !\is_string($arg->name) || 'this' === $arg->name) {
                continue; // pas une variable simple : ce n'est pas notre cas
            }

            $test = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod
                && $n->getStartLine() <= $line && $line <= $n->getEndLine());
            if (!$test instanceof Node\Stmt\ClassMethod || isset($this->definedNames($test)[$arg->name])) {
                continue; // la variable est affectée quelque part : null vient d'ailleurs
            }

            $class = trim($e['expected']);
            $key = $test->name->toString() . '$' . $arg->name;
            if (isset($done[$key]) || !$this->instantiable($class) || null === $first = $this->firstUse($test, $arg->name)) {
                continue;
            }
            $done[$key] = true;

            $pos = $first->getStartFilePos();
            $inserts[$pos] = ($inserts[$pos] ?? '') . sprintf('$%s = new \%s();', $arg->name, $class) . "\n" . SourceSpan::indentAt($testCode, $pos);
        }
        if ([] === $inserts) {
            return null;
        }

        krsort($inserts); // de la fin vers le début : les positions restent valides
        foreach ($inserts as $pos => $text) {
            $testCode = substr_replace($testCode, $text, $pos, 0);
        }

        return $testCode;
    }

    /**
     * @return array<string, true> variables paramètres, affectées, itérées, capturées ou globales dans la méthode
     */
    private function definedNames(Node\Stmt\ClassMethod $test): array
    {
        $finder = new NodeFinder();
        $names = [];
        $collect = static function (?Node $node) use (&$names, $finder): void {
            if (null === $node) {
                return;
            }
            foreach ($finder->findInstanceOf($node, Node\Expr\Variable::class) as $variable) {
                if (\is_string($variable->name)) {
                    $names[$variable->name] = true;
                }
            }
        };

        foreach ($test->params as $param) {
            $collect($param->var);
        }
        $writes = $finder->find($test->stmts ?? [], static fn (Node $n): bool => $n instanceof Node\Expr\Assign
            || $n instanceof Node\Expr\AssignRef || $n instanceof Node\Expr\AssignOp
            || $n instanceof Node\Stmt\Foreach_ || $n instanceof Node\Stmt\Catch_
            || $n instanceof Node\Stmt\Global_ || $n instanceof Node\Stmt\Static_);

        foreach ($writes as $n) {
            if ($n instanceof Node\Stmt\Foreach_) {
                $collect($n->keyVar);
                $collect($n->valueVar);
            } elseif ($n instanceof Node\Stmt\Catch_) {
                $collect($n->var);
            } elseif ($n instanceof Node\Stmt\Global_) {
                array_map($collect, $n->vars);
            } elseif ($n instanceof Node\Stmt\Static_) {
                foreach ($n->vars as $staticVar) {
                    $collect($staticVar->var);
                }
            } else {
                $collect($n->var);
            }
        }

        return $names;
    }

    /**
     * Classe instanciable avec `new X()` (ni union, ni abstraite, ni interface, ni argument obligatoire).
     */
    private function instantiable(string $class): bool
    {
        if (str_contains($class, '|') || str_starts_with($class, '?') || !class_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->isInstantiable() && 0 === ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0);
    }

    /**
     * Première instruction de premier niveau de la méthode qui utilise la variable.
     */
    private function firstUse(Node\Stmt\ClassMethod $test, string $name): ?Node\Stmt
    {
        foreach ($test->stmts ?? [] as $statement) {
            foreach ((new NodeFinder())->findInstanceOf($statement, Node\Expr\Variable::class) as $variable) {
                if ($variable->name === $name) {
                    return $statement;
                }
            }
        }

        return null;
    }
}