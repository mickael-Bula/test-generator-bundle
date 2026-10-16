<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Resolver\MissingClassResolver;

/** @noinspection PhpUnused */
final readonly class MissingClassImportInterceptor implements ErrorInterceptorInterface
{
    // PHPUnit : Class or interface "X" does not exist | PHP : Class "X" not found
    private const PATTERN = '/(?:Class or interface|Class|Interface|Trait|Enum) "(?<fqcn>[^"]+)"(?: does not exist| not found)/';

    public function __construct(
        private MissingClassResolver $classResolver,
        private PhpUnitFailureLocator $locator,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return [] !== $this->classResolver->missingFrom($errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $m);
        $missing = array_values(array_unique($m['fqcn'])); // la même erreur se répète pour chaque test

        if ([] === $missing) {
            return null;
        }

        $failure = $this->locator->locate($errorOutput);
        $testNamespace = null !== $failure->testClass ? $this->namespaceOf($failure->testClass) : null;

        $hints = array_map(
            fn (string $fqcn): string => $this->hint($fqcn, $testNamespace, $failure->sut),
            $missing,
        );

        return implode("\n", $hints)
            . "\nNe modifie que les noms de classes (FQCN complets, sans `use`) : "
            . "ni les mocks, ni les assertions, ni la structure du test ne changent.\n";
    }

    private function hint(string $missing, ?string $testNamespace, ?string $sut): string
    {
        $short = $this->shortName($missing);
        $namespace = $this->namespaceOf($missing);
        [$source, $candidates] = $this->classResolver->resolve($short, $sut);

        $cause = $namespace === $testNamespace
            ? sprintf('le nom court `%s` n\'est pas importé : PHP l\'a résolu dans le namespace du test (`%s`).', $short, $namespace)
            : sprintf('le namespace `%s` est incorrect pour `%s`.', $namespace, $short);

        $md = sprintf("### Classe introuvable : `%s`\n- Cause : %s\n", $missing, $cause);

        if (1 === \count($candidates)) {
            return $md . sprintf(
                    "- Classe réelle (%s) : `\\%s`\n"
                    . "- Correction : remplace chaque occurrence par `\\%s` (FQCN complet, antislash initial). "
                    . "N'ajoute AUCUN `use` : ils sont générés automatiquement quand le test est au vert.\n",
                    $source, $candidates[0], $candidates[0],
                );
        }

        if ([] !== $candidates) {
            return $md . sprintf("- Plusieurs classes portent ce nom (%s) :\n", $source)
                . implode('', array_map(static fn (string $c): string => "  - `$c`\n", $candidates))
                . "- Correction : choisis celle qui correspond au type attendu par le SUT, puis ajoute son `use`.\n";
        }

        $similar = $this->classResolver->similar($short);

        return $md . "- Aucune classe de ce nom dans la signature du SUT, ses imports ou `src/` : ne l'invente pas.\n"
            . ([] !== $similar
                ? '- Noms proches : ' . implode(', ', array_map(static fn (string $c): string => "`$c`", $similar)) . "\n"
                : '')
            . "- Correction : utilise exactement le type déclaré dans le constructeur du SUT.\n";
    }

    private function shortName(string $fqcn): string
    {
        return basename(str_replace('\\', '/', $fqcn));
    }

    private function namespaceOf(string $fqcn): string
    {
        return substr($fqcn, 0, (int) strrpos($fqcn, '\\'));
    }
}