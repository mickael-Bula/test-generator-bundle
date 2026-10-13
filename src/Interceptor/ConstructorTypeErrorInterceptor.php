<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class ConstructorTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, '__construct()') && str_contains($errorOutput, 'must be of type') && str_contains($errorOutput, 'null given');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(
            '/TypeError:\s+([^:]+)::__construct\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+null given/i',
            $errorOutput,$matches
        )) {
            return null;
        }

        $className =$matches[1];
        $argNum =$matches[2];
        $argName =$matches[3];
        $expectedType = trim($matches[4]);

        return sprintf(
            "INDICE DE RÉFLEXION CONSTRUCTEUR (CRITIQUE) :\n"
            . "Erreur de type dans le constructeur de '%s' à l'argument #%s (\$%s).\n"
            . "Il est STRICTEMENT INTERDIT de passer `null` pour un argument typé (attendu : `%s`).\n"
            . "Tu dois impérativement créer un mock complet pour cette dépendance via `$this->createMock(%s::class)` dans le `setUp()` et le passer en argument.",
            $className,
            $argNum,$argName,
            $expectedType,$expectedType
        );
    }
}