<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use PhpParser\Node;
use PhpParser\NodeFinder;

final class NullOriginResolver
{
    /**
     * @param string[] $neighborhood FQCN issus de la repo-map
     * @return list<array{class: string, getter: string, returnType: string, ctorParam: ?string}>
     * @throws \ReflectionException
     */
    public function resolve(Node\Expr $arg, array $neighborhood): array
    {
        $getters = array_map(
            fn(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $n) => $n->name->toString(),
            (new NodeFinder())->find($arg, fn(Node $n) =>
                ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall)
                && $n->name instanceof Node\Identifier),
        );

        $found = [];
        foreach ($getters as $getter) {
            foreach ($neighborhood as $fqcn) {
                if (!method_exists($fqcn, $getter)) {
                    continue;
                }
                $returnType = (new \ReflectionMethod($fqcn, $getter))->getReturnType();
                if ($returnType === null || !$returnType->allowsNull()) {
                    continue;
                }
                $found[] = [
                    'class'      => $fqcn,
                    'getter'     => $getter,
                    'returnType' => (string) $returnType,
                    'ctorParam'  => $this->backingCtorParam($fqcn, $getter),
                ];
            }
        }
        return $found;
    }

    /** getLvcClose() -> paramètre de constructeur `lvcClose` (convention get/is/has)
     * @throws \ReflectionException
     */
    private function backingCtorParam(string $fqcn, string $getter): ?string
    {
        $name = lcfirst((string) preg_replace('/^(get|is|has)/', '', $getter));
        foreach ((new \ReflectionClass($fqcn))->getConstructor()?->getParameters() ?? [] as $p) {
            if ($p->getName() === $name) {
                return trim(sprintf('%s $%s%s', $p->getType() ?? '', $p->getName(),
                    $p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''));
            }
        }
        return null;
    }
}
