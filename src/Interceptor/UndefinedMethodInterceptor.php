<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use PHPUnit\Framework\TestCase;

/** @noinspection PhpUnused */
final readonly class UndefinedMethodInterceptor implements ErrorInterceptorInterface
{
    // PHP : "Call to undefined method X::m()" ; PHPStan : "Call to an undefined method X::m()".
    // expects()/method() restent traités par UndefinedMockMethodInterceptor.
    private const PATTERN = '/Call to (?:an )?undefined method (?<class>[\w\\\\]+)::(?!expects\(|method\()(?<method>\w+)\(\)/';

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $m, PREG_SET_ORDER);

        $md = '';
        foreach (array_unique(array_map(static fn (array $x): string => $x['class'] . '::' . $x['method'], $m)) as $pair) {
            [$class, $method] = explode('::', $pair);
            if (class_exists($class) || interface_exists($class)) {
                $md .= $this->hint(new \ReflectionClass($class), $method);
            } elseif (str_ends_with($class, 'Test')) {
                $md .= $this->testClassHint($method);
            }
        }

        return '' === $md ? null : $md . "N'appelle que des méthodes existantes de ces listes ; n'en invente aucune.\n";
    }

    private function hint(\ReflectionClass $reflectionClass, string $missing): string
    {
        $base = strtolower((string) preg_replace('/^(set|get|is|has|add|remove)(?=[A-Z])/', '', $missing));
        $publics = array_values(array_filter(
            $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => !$m->isConstructor(),
        ));

        $md = sprintf("### Méthode inexistante : `\\%s::%s()`\n", $reflectionClass->getName(), $missing);

        foreach ($publics as $m) {
            if ('' !== $base && str_contains(strtolower($m->getName()), $base)) {
                $md .= sprintf("- Méthode proche : `%s`\n%s\n", $this->signature($m), preg_replace('/^/m', '      ', $this->source($m)));
                $md .= "  Si elle est calculée (pas de setter), agis sur les données qu'elle lit.\n";
            }
        }

        $mutators = array_map(
            fn (\ReflectionMethod $m): string => '`' . $this->signature($m) . '`',
            array_filter($publics, static fn (\ReflectionMethod $m): bool => 1 === preg_match('/^(set|add|remove)[A-Z]/', $m->getName())),
        );

        $constructor = $reflectionClass->getConstructor();
        if (null !== $constructor && $constructor->getNumberOfParameters() > 0) {
            $md .= sprintf(
                "- Constructeur : `new \\%s%s`\n",
                $reflectionClass->getName(),
                substr($this->signature($constructor), \strlen('__construct'))
            );
        }

        return $md . '- Mutateurs disponibles : ' . ([] !== $mutators ? implode(', ', $mutators) : 'aucun') . "\n";
    }

    private function signature(\ReflectionMethod $m): string
    {
        return sprintf(
            '%s(%s)%s',
            $m->getName(),
            implode(', ', array_map(
                static fn (\ReflectionParameter $p): string => trim(($p->getType() ?? '') . ' $' . $p->getName()),
                $m->getParameters(),
            )),
            $m->hasReturnType() ? ': ' . $m->getReturnType() : '',
        );
    }

    private function source(\ReflectionMethod $m): string
    {
        $file = $m->getFileName();
        if (false === $file) {
            return '';
        }
        $lines = \array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1);

        return \count($lines) <= 15 ? implode("\n", $lines) : '// (corps trop long)';
    }

    private function testClassHint(string $method): string
    {
        $expect = array_map(
            static fn (\ReflectionMethod $m): string => '`' . $m->getName() . '()`',
            array_filter(
                (new \ReflectionClass(TestCase::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'expect'),
            ),
        );
        sort($expect);

        return sprintf('### Méthode inexistante sur la classe de test : `$this->%s()`', $method) . "\n"
            . sprintf("- `%s()` n'est ni une méthode de PHPUnit, ni un helper défini dans le test.\n", $method)
            . '- Attentes d\'exception réellement disponibles : ' . implode(', ', $expect) . ".\n"
            . "- Correction : appelle l'une d'elles AVANT l'action (`\$this->expectExceptionMessage('…')`), "
            . "ou définis le helper comme méthode `private` du test, ou supprime l'appel. "
            . "Une valeur attendue se passe par un jeu de données (`#[DataProvider]`), pas par une méthode inventée.\n";
    }
}