<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/** @noinspection PhpUnused */
final readonly class StubShadowingInterceptor implements ErrorInterceptorInterface
{
    public function __construct(
        private PhpUnitFailureLocator $locator,
        private Parser $parser,
    ) {}

    public function supports(string $errorOutput): bool
    {
        // La vérification par 'str_contains' évite l’analyse sur les rapports sans rapport.
        return (str_contains($errorOutput, 'Undefined array key') || str_contains($errorOutput, 'Expectation failed for method name is'))
            && [] !== $this->lines($errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $lines = $this->lines($errorOutput);
        if ([] === $lines) {
            return null;
        }

        return "### Stubs masqués\n"
            . "PHPUnit renvoie la valeur du PREMIER stub enregistré qui correspond à l'appel ; les suivants ne font que compter les appels.\n"
            . implode("\n", $lines) . "\n"
            . "Correction : retire du `setUp()` tout stub (`->method(...)`). Si plusieurs tests partagent la même base, crée une méthode "
            . "privée `stubXxx(...)` (valeurs en paramètres) appelée explicitement par chaque test, avec UN seul stub par méthode et par test. "
            . "Un tableau retourné par un mock doit contenir toutes les clés que le SUT lit.\n";
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function stubs(Node\Stmt\ClassMethod $method, NodeFinder $finder): array
    {
        $found = [];
        foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
            if (!$this->is($call, 'method')) {
                continue;
            }
            /** @var Node\Expr\ $call */
            $target = $this->is($call->var, 'expects') ? $call->var->var : $call->var;
            $arg = $call->args[0]->value ?? null;

            if ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier && $arg instanceof Node\Scalar\String_) {
                $found[] = [$target->name->toString() . '::' . $arg->value, $call->name->getStartLine()];
            }
        }
        usort($found, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $found;
    }

    private function is(Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && $name === $node->name->toString();
    }

    /**
     * @return list<string>
     */
    private function lines(string $errorOutput): array
    {
        $file = $this->locator->locate($errorOutput)->testFile;
        if (null === $file) {
            return [];
        }

        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }

        $finder = new NodeFinder();
        $stubs = []; // méthode du test => list<[clé "propriété::méthode", ligne]>
        foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $method) {
            $stubs[$method->name->toString()] = $this->stubs($method, $finder);
        }

        $setUp = [];
        foreach ($stubs['setUp'] ?? [] as [$key, $line]) {
            $setUp[$key] ??= $line;
        }

        preg_match_all('/::(\w+)/', $errorOutput, $names);
        $failing = array_flip($names[1]);

        $lines = [];
        foreach ($stubs as $test => $list) {
            if ('setUp' === $test || !isset($failing[$test])) {
                continue;
            }
            $seen = [];
            foreach ($list as [$key, $line]) {
                if (isset($setUp[$key])) {
                    $lines[] = sprintf('- `%s` : le stub de `%s` (ligne %d) est masqué par celui de `setUp()` (ligne %d).', $test, $key, $line, $setUp[$key]);
                } elseif (isset($seen[$key])) {
                    $lines[] = sprintf('- `%s` : `%s` est stubé deux fois (lignes %d puis %d) ; seul le premier compte.', $test, $key, $seen[$key], $line);
                }
                $seen[$key] ??= $line;
            }
        }

        return $lines;
    }
}