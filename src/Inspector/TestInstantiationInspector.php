<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Inspector;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

final readonly class TestInstantiationInspector
{
    public function __construct(private Parser $parser) {}

    /** @return list<int> lignes des `new $class(...)` où $param est omis ou passé à null
     * @throws \ReflectionException
     */
    public function missingOrNull(string $testFile, string $class, string $param): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($this->parser->parse(file_get_contents($testFile)));

        $constructorNames = array_map(
            fn(\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );
        $position = array_search($param, $constructorNames, true);

        $lines = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Expr\New_::class) as $new) {
            if (!$new->class instanceof Node\Name || $new->class->toString() !== $class) {
                continue;
            }
            $valued = false;
            foreach ($new->args as $key => $arg) {
                if ($arg->unpack) {
                    $valued = true; // spread : indéterminé
                    break;
                }
                $targets = $arg->name?->toString() === $param || ($arg->name === null && $key === $position);
                $isNull  = $arg->value instanceof Node\Expr\ConstFetch
                    && strtolower($arg->value->name->toString()) === 'null';
                if ($targets && !$isNull) {
                    $valued = true;
                }
            }
            if (!$valued) {
                $lines[] = $new->getStartLine();
            }
        }
        return $lines;
    }
}
