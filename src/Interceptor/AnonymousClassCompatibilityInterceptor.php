<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class AnonymousClassCompatibilityInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Could not check compatibility between') && str_contains($errorOutput, 'is not available');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(
            '/Could not check compatibility between.+because class\s+(\S+)\s+is not available/i',
            $errorOutput,$matches
        )) {
            return null;
        }

        $missingClassName =$matches[1];

        return sprintf(
            "INDICE DE CORRECTION CLASSE ANONYME (COMPATIBILITÉ DE SIGNATURE) :\n"
            . "Erreur fatale : La classe `%s` n'est pas disponible ou résolue dans la signature de méthode de ta classe anonyme.\n"
            . "Règle PHP pour les classes anonymes implémentant une interface : "
            . "**Tous** les types d'arguments dans les signatures de méthodes (`execute`, etc.) doivent utiliser leur **FQCN complet** "
            . "(ex : `\\App\\Entity\\Position`, `\\App\\Dto\\MarketData\\CacDailyDto`).\n"
            . "Modifie tes classes anonymes pour remplacer les noms courts par leurs chemins de namespace complets dans les signatures.",
            $missingClassName
        );
    }
}