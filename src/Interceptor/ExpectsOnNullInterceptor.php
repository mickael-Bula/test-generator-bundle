<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class ExpectsOnNullInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Call to a member function expects() on null');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match('/Error:\s+Call to a member function expects\(\)\s+on null/i', $errorOutput)) {
            return null;
        }

        return "INDICE DE CORRECTION ARCHITECTURE TEST (ERREUR EXPECTS ON NULL) :\n"
            . "Erreur fatale : Tu tentes d'appeler `expects()` sur une variable de mock qui vaut `null`.\n"
            . "Règle AAA et cycle de vie PHPUnit :\n"
            . "1. **Arrange** : Tu dois impérativement instancier ton mock "
            . "(ex: `\$this->repo = \$this->createMock(...)`) **avant** de configurer ses attentes (`\$this->repo->expects(...)`).\n"
            . "2. **Évite les fonctions d'aide personnalisées** : "
            . "Initialise tes mocks et ta SUT soit directement dans la méthode native `setUp()`, "
            . "soit au tout début de chaque méthode de test, jamais dans une méthode externe appelée après les `expects()`.";
    }
}