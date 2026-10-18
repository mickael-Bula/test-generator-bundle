<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * N'implémente PAS l'interface : elle se retrouverait dans son propre itérateur (référence circulaire).
 */
final readonly class PreFixerChain
{
    /** @param iterable<TestPreFixerInterface> $fixers */
    public function __construct(
        #[AutowireIterator('mika_test_generator.pre_fixer')] private iterable $fixers,
    ) {
    }

    /**
     * Vérifie qu'un correcteur ne fait pas partie de la liste des correcteurs en échec avant d'être appliqué.
     *
     * Un seul correcteur par passe : les numéros de ligne d'un rapport ne valent que pour le code d'origine.
     * La passe suivante relance PHPUnit/PHPStan et donne un rapport à jour.
     *
     * @return array{code: string, fixer: class-string}|null
     */
    public function apply(string $errorOutput, string $testCode, array $skip = []): ?array
    {
        foreach ($this->fixers as $fixer) {
            if (\in_array($fixer::class, $skip, true)) {
                continue;
            }
            if (null !== $patched = $fixer->fix($errorOutput, $testCode)) {
                return ['code' => $patched, 'fixer' => $fixer::class];
            }
        }

        return null;
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        return $this->apply($errorOutput, $testCode)['code'] ?? null;
    }

    /**
     * @return list<class-string> ordre de passage
     */
    public function names(): array
    {
        return array_map(
            static fn (TestPreFixerInterface $f): string => $f::class, iterator_to_array($this->fixers, false)
        );
    }
}