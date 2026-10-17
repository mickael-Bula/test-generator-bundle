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

    public function fix(string $errorOutput, string $testCode): ?string
    {
        foreach ($this->fixers as $fixer) {
            // Un seul correcteur par passe : les numéros de ligne d'un rapport ne valent que pour le code d'origine.
            // La passe suivante relance PHPUnit/PHPStan et donne un rapport à jour.
            if (null !== $patched = $fixer->fix($errorOutput, $testCode)) {
                return $patched;
            }
        }

        return null;
    }
}