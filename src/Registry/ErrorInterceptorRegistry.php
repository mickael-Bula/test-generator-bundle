<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Registry;

use Mika\TestGeneratorBundle\Interceptor\ErrorInterceptorInterface;


final readonly class ErrorInterceptorRegistry
{
    /**
     * @param iterable<ErrorInterceptorInterface> $interceptors
     */
    public function __construct(
        private iterable $interceptors,
    ) {}

    public function resolveFromErrorOutput(string $errorOutput): ?string
    {
        // 1. Parcours de tous les intercepteurs spécialisés
        foreach ($this->interceptors as $interceptor) {
            if ($interceptor->supports($errorOutput)) {
                $hint = $interceptor->intercept($errorOutput);
                if (null !== $hint) {
                    return $hint;
                }
            }
        }

        return null;
    }
}
