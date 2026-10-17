<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Resolver\MissingClassResolver;

final readonly class MissingClassPreFixer implements TestPreFixerInterface
{
    public function __construct(
        private PhpUnitFailureLocator $locator,
        private MissingClassResolver $resolver,
        private FqcnQualifier $qualifier,
    ) {}

    /** @return string|null le test corrigé, ou null si rien n'a pu être corrigé sans ambiguïté */
    public function fix(string $errorOutput, string $testCode): ?string
    {
        $failure = $this->locator->locate($errorOutput);
        $testNamespace = null !== $failure->testClass
            ? substr($failure->testClass, 0, (int) strrpos($failure->testClass, '\\'))
            : '';

        $patched = $testCode;
        foreach ($this->resolver->missingFrom($errorOutput) as $missing) {
            [, $candidates] = $this->resolver->resolve(basename(str_replace('\\', '/', $missing)), $failure->sut);
            if (1 !== \count($candidates)) {
                continue; // inconnu ou ambigu : le LLM tranchera, guidé par l'indice de l'intercepteur
            }
            $patched = $this->qualifier->qualify($patched, $missing, $candidates[0], $testNamespace);
        }

        return $patched !== $testCode ? $patched : null;
    }
}
