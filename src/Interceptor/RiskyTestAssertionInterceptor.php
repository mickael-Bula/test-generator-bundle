<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Extractor\MethodSource;
use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Interceptor\Support\SutPropertyFinder;
use Mika\TestGeneratorBundle\Reflection\ReturnedStateAnalyzer;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

/** @noinspection PhpUnused */
final readonly class RiskyTestAssertionInterceptor implements ErrorInterceptorInterface
{
    private const NO_ASSERTION = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\RThis test did not perform any assertions/m';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private Parser $parser,
        private SutPropertyFinder $sutProperty,
        private ReturnedStateAnalyzer $returned,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::NO_ASSERTION, $errorOutput);
    }

    /**
     * @throws \ReflectionException
     */
    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::NO_ASSERTION, $errorOutput, $m);

        $located = $this->locator->locate($errorOutput);
        $stmts = $this->parse($located->testFile);
        $sut = null !== $located->sut && class_exists($located->sut) ? new \ReflectionClass($located->sut) : null;
        $property = null !== $sut ? $this->sutProperty->find($stmts, $sut->getName()) : null;

        $md = "### Tests sans aucune assertion (PHPUnit les déclare « risky »)\n";
        foreach (array_unique($m['test']) as $test) {
            $md .= sprintf("\n- `%s` : le test s'exécute mais ne vérifie rien.\n", $test);
            $md .= $this->advice($test, $stmts, $sut, $property);
        }

        return $md . "\nRègles :\n"
            . "- Ne supprime pas le test et n'ajoute ni `assertTrue(true)` ni `markTestSkipped()` : ajoute une vraie vérification.\n"
            . "- Une vérification = valeur retournée, état d'un objet modifié par le SUT (`assertSame(attendu, \$objet->getX())`) "
            . "ou appel de mock (`expects(self::exactly(n))` déclaré AVANT l'appel).\n"
            . "- Donne à chaque donnée d'entrée une valeur DISTINCTE : une inversion de champs dans le code serait alors détectée.\n";
    }

    /**
     * @throws \ReflectionException
     */
    private function advice(string $test, array $stmts, ?\ReflectionClass $sut, ?string $property): string
    {
        $fallback = "  - Ajoute au moins une vérification sur le résultat ou l'état observable.\n";
        $finder = new NodeFinder();
        $method = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $test);
        if (!$method instanceof Node\Stmt\ClassMethod || null === $sut) {
            return $fallback;
        }
        $body = $method->stmts ?? [];

        $sutVars = []; // variables locales créées avec `new SUT`
        foreach ($finder->findInstanceOf($body, Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)
                && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name
                && $assign->expr->class->toString() === $sut->getName()) {
                $sutVars[$assign->var->name] = true;
            }
        }

        $md = '';
        foreach ($finder->findInstanceOf($body, Node\Expr\MethodCall::class) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            $receiver = $call->var;
            $onSut = ($receiver instanceof Node\Expr\PropertyFetch && null !== $property
                    && $receiver->var instanceof Node\Expr\Variable && 'this' === $receiver->var->name
                    && $receiver->name instanceof Node\Identifier && $receiver->name->toString() === $property)
                || ($receiver instanceof Node\Expr\Variable && \is_string($receiver->name) && isset($sutVars[$receiver->name]));

            if ($onSut && $sut->hasMethod($name) && $sut->getMethod($name)->isPublic()) {
                $md .= $this->describeCall($call, $sut->getMethod($name), $body);
            }
        }

        return '' !== $md ? $md : $fallback;
    }

    /**
     * @throws \ReflectionException
     */
    private function describeCall(Node\Expr\MethodCall $call, \ReflectionMethod $m, array $body): string
    {
        $printer = new Standard();
        $signature = sprintf(
            '%s(%s)%s',
            $m->getName(),
            implode(
                ', ',
                array_map(static fn (\ReflectionParameter $p): string => trim(($p->getType() ?? '') . ' $' . $p->getName()), $m->getParameters())
            ),
            $m->hasReturnType() ? ': ' . $m->getReturnType() : '',
        );
        $md = sprintf("  - Il appelle `%s`.\n", $signature);

        $type = $m->getReturnType();
        if (null === $type || \in_array((string) $type, ['void', 'never'], true)) {
            return $md . "  - La méthode ne retourne rien : vérifie son EFFET (état d'un argument qu'elle modifie, ou appel de mock "
                . "`expects(self::exactly(n))` déclaré AVANT l'appel).\n" . $this->code($m);
        }

        // Variable qui reçoit le résultat, sinon `$result`
        $var = 'result';
        foreach ((new NodeFinder())->findInstanceOf($body, Node\Expr\Assign::class) as $assign) {
            if ($assign->expr === $call && $assign->var instanceof Node\Expr\Variable && \is_string($assign->var->name)) {
                $var = $assign->var->name;
                $captured = true;
            }
        }
        $md .= isset($captured)
            ? sprintf("  - Le résultat est dans `\$%s`.\n", $var)
            : sprintf("  - Récupère le résultat : `\$%s = %s;`\n", $var, $printer->prettyPrintExpr($call));

        $entries = $this->returned->describe($m, $this->arguments($call, $m, $printer));
        if ([] !== $entries) {
            $md .= "  - Assertions candidates, déduites de ce que le code affecte à l'objet retourné (à relire, et à compléter par les cas limites) :\n";
            foreach ($entries as $e) {
                $md .= '      ' . $this->assertion($e, $var) . "\n";
            }

            return $md;
        }

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && class_exists($type->getName())) {
            $getters = [];
            foreach ((new \ReflectionClass($type->getName()))->getMethods(\ReflectionMethod::IS_PUBLIC) as $g) {
                if (1 === preg_match('/^(get|is|has)[A-Z]/', $g->getName()) && 0 === $g->getNumberOfRequiredParameters()) {
                    $getters[] = sprintf('`%s(): %s`', $g->getName(), $g->hasReturnType() ? $g->getReturnType() : 'mixed');
                }
            }

            return $md . '  - Accesseurs de l\'objet retourné : ' . implode(', ', \array_slice($getters, 0, 15))
                . ". Vérifie ceux que la méthode produit.\n" . $this->code($m);
        }

        return $md . "  - Compare `\$$var` à une EXPRESSION des données d'entrée (la formule du code ci-dessous), "
            . "jamais à un résultat calculé de tête.\n"
            . $this->code($m);
    }

    /**
     * @return array<string, string> paramètre de la méthode => expression passée par le test
     */
    private function arguments(Node\Expr\MethodCall $call, \ReflectionMethod $method, Standard $printer): array
    {
        $params = $method->getParameters();
        $map = [];
        foreach ($call->args as $i => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                return [];
            }
            $name = $arg->name?->toString() ?? (($params[$i] ?? null)?->getName());
            if (null !== $name) {
                $map[$name] = $printer->prettyPrintExpr($arg->value);
            }
        }

        return $map;
    }

    /**
     * @param array{getter: string, expression: string, volatile: bool} $e
     */
    private function assertion(array $e, string $var): string
    {
        $actual = sprintf('$%s->%s()', $var, $e['getter']);
        $expression = $e['expression'];

        return match (true) {
            $e['volatile'] => 1 === preg_match('/new\s+(\\\\?[\w\\\\]+)/', $expression, $c)
                ? sprintf('self::assertInstanceOf(\\%s::class, %s);   // valeur non déterministe : on vérifie le type', ltrim($c[1], '\\'), $actual)
                : sprintf('self::assertNotNull(%s);   // valeur non déterministe', $actual),
            'true' === strtolower($expression) => sprintf('self::assertTrue(%s);', $actual),
            'false' === strtolower($expression) => sprintf('self::assertFalse(%s);', $actual),
            'null' === strtolower($expression) => sprintf('self::assertNull(%s);', $actual),
            default => sprintf('self::assertSame(%s, %s);', $expression, $actual),
        };
    }

    private function code(\ReflectionMethod $m): string
    {
        $code = MethodSource::full($m);

        return null === $code
            ? "  - Code de la méthode trop long : lis sa signature et déduis la logique.\n"
            : sprintf(
                "  - Code réel de `%s::%s()` :\n%s\n",
                $m->getDeclaringClass()->getShortName(),
                $m->getName(),
                preg_replace('/^/m', '        ', $code)
            );
    }

    private function parse(?string $file): array
    {
        if (null === $file || !is_file($file)) {
            return [];
        }
        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());

            return $traverser->traverse($this->parser->parse((string) file_get_contents($file)) ?? []);
        } catch (Error) {
            return [];
        }
    }
}