<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Psr\Cache\InvalidArgumentException;

/**
 * LiteralTypePreFixer ne traite que les retours scalaires (willReturn(100) pour float).
 * Quand le type déclaré est une classe, le LLM doit fournir un objet :
 * l’intercepteur lui dit comment le construire, d’après la classe réelle.
 *
 * @noinspection PhpUnused
 */
final readonly class IncompatibleReturnValueInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\r\n]*\R[\w\\\\]*IncompatibleReturnValueException: Method (?<method>\w+) may not return value of type (?<given>[\w\\\\]+), its declared return type is "(?<expected>[^"]+)"[^\r\n]*(?:\R\R\S*Test\.php:(?<line>\d+))?/m';

    public function __construct(private PhpUnitFailureLocator $locator) {}

    public function supports(string $errorOutput): bool
    {
        return [] !== $this->errors($errorOutput);
    }

    /**
     * @throws \ReflectionException|InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        $errors = $this->errors($errorOutput);
        if ([] === $errors) {
            return null;
        }

        $file = $this->locator->locate($errorOutput)->testFile;
        $lines = null !== $file && is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

        $md = "### Valeur retournée par un mock incompatible avec le type de retour déclaré\n";
        foreach ($errors as $e) {
            $md .= sprintf(
                "- `%s` : `%s()` doit retourner `\\%s`, le test lui fait retourner `%s`.\n",
                $e['test'], $e['method'], ltrim($e['expected'], '\\'), $e['given'],
            );
            if (isset($e['line'], $lines[(int) $e['line'] - 1])) {
                $md .= sprintf("  - Ligne du test : `%s`\n", trim($lines[(int) $e['line'] - 1]));
            }
            $md .= '  - Correction : remplace la valeur par ' . $this->advice(ltrim($e['expected'], '\\')) . "\n";
        }

        return $md . "Ne retourne jamais un tableau ou un scalaire à la place d'un objet : le SUT appelle des méthodes sur la valeur reçue.\n";
    }

    /**
     * Seuls les types classe sont traités ici : les scalaires relèvent de LiteralTypePreFixer.
     */
    private function errors(string $output): array
    {
        preg_match_all(self::PATTERN, $output, $m, PREG_SET_ORDER);

        return array_values(array_filter($m, static fn (array $e): bool => !str_contains($e['expected'], '|')
            && (class_exists(ltrim($e['expected'], '\\')) || interface_exists(ltrim($e['expected'], '\\')))));
    }

    /**
     * @throws \ReflectionException
     */
    private function advice(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $required = $constructor?->getNumberOfRequiredParameters() ?? 0;
        $parameters = implode(', ', array_map(
            static fn (\ReflectionParameter $p): string => trim(($p->getType() ?? '') . ' $' . $p->getName()),
            $constructor?->getParameters() ?? [],
        ));

        return match (true) {
            $reflection->isInterface() || $reflection->isAbstract() => sprintf('`$this->createMock(\%s::class)` (interface ou classe abstraite).', $class),
            $reflection->isFinal() && 0 === $required => sprintf('`new \%s()` (classe finale, donc non mockable ; constructeur sans argument).', $class),
            $reflection->isFinal() => sprintf('`new \%s(%s)` (classe finale, donc non mockable).', $class, $parameters),
            0 === $required => sprintf('`new \%s()`, ou `$this->createMock(\%s::class)` si seul son type compte.', $class, $class),
            default => sprintf('`$this->createMock(\%s::class)` (évite les dépendances de son constructeur) ; sinon `new \%s(%s)`.', $class, $class, $parameters),
        };
    }
}