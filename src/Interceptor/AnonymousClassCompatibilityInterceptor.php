<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class AnonymousClassCompatibilityInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/Could not check compatibility between (?<left>.+?) and (?<right>.+?), because (?<kind>[a-z]+) (?<missing>[\w\\\\]+) is not available/';

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(self::PATTERN, $errorOutput,$matches)) {
            return null;
        }

        $missingClassName = $matches['missing'];

        return sprintf(
            "INDICE DE CORRECTION CLASSE ANONYME (COMPATIBILITÉ DE SIGNATURE) :\n"
            . "Erreur fatale : La classe `%s` n'est pas disponible ou résolue dans la signature de méthode de ta classe anonyme.\n"
            . "Règle PHP pour les classes anonymes implémentant une interface : "
            . "**Tous** les types d'arguments dans les signatures de méthodes (`execute`, etc.) doivent utiliser leur **FQCN complet** "
            . "(ex : `\\App\\Entity\\MaClasse`, `\\App\\Dto\\MonDto`).\n"
            . "Modifie tes classes anonymes pour remplacer les noms courts par leurs chemins de namespace complets dans les signatures.",
            $missingClassName
        );
    }
}