<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class FinalClassMockInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'ClassIsFinalException')
            || str_contains($errorOutput, 'is declared "final" and cannot be doubled');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match('/Class\s+"([^"]+)"\s+is declared "final" and cannot be doubled/i', $errorOutput,$matches)) {
            return null;
        }

        $className =$matches[1];
        $shortName = basename(str_replace('\\', '/',$className));

        return sprintf(
            "INDICE DE CORRECTION CRITIQUE (CLASSE FINALE / INTERDICTION DE MOCKER) :\n"
            . "Erreur fatale PHPUnit : La classe `%s` est déclarée `final` et ne peut pas être doublée via `createMock()`.\n"
            . "❌ **Règle absolue** : Il est strictement interdit d'utiliser `createMock()` sur un DTO, un Value Object ou une Entité `final`.\n"
            . "✔️ **Solution obligatoire** : Remplace l'appel à `\$this->createMock(%s::class)` par une instanciation directe "
            . "avec le mot-clé `new` (ex: `new \\%s(...)`) en passant les arguments requis à son constructeur.",
            $className,$shortName,
            ltrim($className, '\\')
        );
    }
}