<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class MockCountExpectationInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'was not expected to be called');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match('/was not expected to be called/i', $errorOutput)) {
            return null;
        }

        return "INDICE DE CORRECTION PHPUNIT (ERREUR DE COMPTAGE D'APPELS DE MOCK) :\n"
            . "Erreur PHPUnit : Un mock a été configuré avec une attente stricte (ex: `expects(self::once())`), "
            . "mais la méthode a été appelée plusieurs fois lors de l'exécution (généralement à cause d'une boucle sur plusieurs éléments).\n\n"
            . "**Correction à appliquer** :\n"
            . "Modifie l'attente du mock concerné pour autoriser plusieurs appels. "
            . "Utilise `expects(self::any())` ou `expects(self::atLeastOnce())` au lieu de `once()` lorsque la méthode est appelée dans une boucle.";
    }
}