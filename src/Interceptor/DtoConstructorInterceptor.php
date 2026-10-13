<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class DtoConstructorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Call to undefined method')
            && (str_contains($errorOutput, 'with') || str_contains($errorOutput, 'set'));
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match('/Call to undefined method ([^:]+)::(with|set)/i', $errorOutput,$matches)) {
            return null;
        }

        $className =$matches[1];
        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);$constructor = $reflection->getConstructor();$params = [];

            if ($constructor) {
                foreach ($constructor->getParameters() as$param) {
                    $params[] = '$' . $param->getName() . ($param->isOptional() ? ' [optionnel]' : ' [requis]');
                }
            }

            $publicMethods = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as$method) {
                if (!$method->isConstructor() && !str_starts_with($method->getName(), '__')) {
                    $publicMethods[] =$method->getName();
                }
            }

            return sprintf(
                "INDICE DE RÉFLEXION PHP CRITIQUE (OBJET IMMUABLE / DTO) :\n" .
                "Erreur fatale : Tu tentes d'appeler une méthode inexistante (`with*` ou `set*`) sur la classe `%s`.\n" .
                "❌ **Rappel** : Les DTOs et Value Objects sont immuables. Ils n'ont **aucun** setter (`set*`).\n\n" .
                "✔️ **API Réelle et exhaustive disponible sur cette classe (extraite par Réflexion)** :\n" .
                "- **Constructeur** : `new \\%s(%s)`\n" .
                "- **Méthodes publiques autorisées** : `%s`\n\n" .
                "Corrige ton code en supprimant les appels à `with*` / `set*` et en passant toutes les valeurs directement dans le constructeur.",
                $className,$className,
                implode(', ', $params),
                implode('(), ', $publicMethods) . '()'
            );
        } catch (\Exception $e) {
            return null;
        }
    }
}