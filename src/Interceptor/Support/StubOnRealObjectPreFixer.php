<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Node;

final readonly class StubOnRealObjectPreFixer implements TestPreFixerInterface
{
    // PHP : "Call to undefined method X::expects()" ; PHPStan : "Call to an undefined method X::method()"
    private const TRIGGER = '/Call to (?:an )?undefined method [\w\\\\]+::(?:expects|method)\(\)/';

    public function __construct(private RealObjectStubFinder $finder)
    {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        if (1 !== preg_match(self::TRIGGER, $errorOutput)) {
            return null;
        }

        $edits = []; // position de début => [position de fin, remplacement]
        foreach ($this->finder->find($testCode) as $stub) {
            if (!$this->canPartialMock($stub['class'], $stub['methods'])) {
                continue;
            }
            $replacement = $this->builder($stub['new'], $stub['class'], $stub['methods'], $testCode);
            if (null !== $replacement) {
                $edits[$stub['new']->getStartFilePos()] = [$stub['new']->getEndFilePos(), $replacement];
            }
        }
        if ([] === $edits) {
            return null;
        }

        krsort($edits); // de la fin vers le début : les positions restent valides
        foreach ($edits as $start => [$end, $replacement]) {
            $testCode = substr_replace($testCode, $replacement, $start, $end - $start + 1);
        }

        return $testCode;
    }

    /**
     * @param list<string> $methods
     */
    private function canPartialMock(string $class, array $methods): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $rc = new \ReflectionClass($class);
        if ($rc->isFinal() || method_exists($class, 'expects') || method_exists($class, 'method')) {
            return false;
        }
        foreach ($methods as $name) {
            if (!$rc->hasMethod($name)) {
                return false; // méthode inventée : c'est le travail de UndefinedMethodInterceptor
            }
            $m = $rc->getMethod($name);
            if (!$m->isPublic() || $m->isFinal() || $m->isStatic()) {
                return false;
            }
        }

        return true;
    }

    /**
     * `new X(a, b)` => `$this->getMockBuilder(\X::class)->onlyMethods([...])->setConstructorArgs([a, b])->getMock()`
     */
    private function builder(Node\Expr\New_ $new, string $class, array $methods, string $code): ?string
    {
        $ctorArgs = '';
        if ([] !== $new->args) {
            foreach ($new->args as $arg) {
                if (!$arg instanceof Node\Arg || $arg->unpack || null !== $arg->name) {
                    return null; // arguments nommés ou dépliés : on ne tente rien
                }
            }
            $first = $new->args[0];
            $last = $new->args[array_key_last($new->args)];
            $ctorArgs = '->setConstructorArgs([' . substr(
                    $code,
                    $first->getStartFilePos(),
                    $last->getEndFilePos() - $first->getStartFilePos() + 1,
                ) . '])';
        }

        return sprintf(
            '$this->getMockBuilder(\\%s::class)->onlyMethods([%s])%s->getMock()',
            $class,
            implode(', ', array_map(static fn (string $m): string => var_export($m, true), $methods)),
            $ctorArgs,
        );
    }
}