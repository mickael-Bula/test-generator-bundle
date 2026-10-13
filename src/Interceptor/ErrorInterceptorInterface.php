<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

interface ErrorInterceptorInterface
{
    /**
     * Détermine si l'intercepteur peut traiter ce type d'erreur.
     */
    public function supports(string $errorOutput): bool;

    /**
     * Analyse l'erreur, extrait le contexte/réflexion et retourne un indice de correction ciblé.
     */
    public function intercept(string $errorOutput): ?string;
}
