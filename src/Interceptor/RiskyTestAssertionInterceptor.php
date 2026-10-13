<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class RiskyTestAssertionInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'This test is not expected to perform assertions but performed');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match('/This test is not expected to perform assertions but performed/i', $errorOutput)) {
            return null;
        }

        return "INDICE DE CORRECTION TEST RISQUÉ (PHPUNIT) :\n"
            . "Alerte PHPUnit : Le test a été marqué comme risqué (`risky test`) car il utilise `expectNotToPerformAssertions()` tout en effectuant des assertions.\n"
            . "Règle PHPUnit : Les attentes sur les mocks (`expects()`, `method()`) comptent **automatiquement** comme des assertions.\n\n"
            . "**Correction à appliquer** :\n"
            . "Supprime immédiatement l'appel à `self::expectNotToPerformAssertions();` dans le ou les tests concernés. "
            . "Laisse les mocks valider les appels.";
    }
}