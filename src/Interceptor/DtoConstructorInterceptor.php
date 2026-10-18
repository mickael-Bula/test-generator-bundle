<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class DtoConstructorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Call to undefined method');
    }

    public function intercept(string $errorOutput): ?string
    {
        // Capture n'importe quelle méthode inexistante sur n'importe quelle classe
        if (!preg_match('/Call to undefined method ([^:]+)::([a-zA-Z0-9_]+)/i', $errorOutput,$matches)) {
            return null;
        }

        $className = $matches[1];
        $methodName = $matches[2];

        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);
            $constructor = $reflection->getConstructor();
            $params = [];

            if ($constructor) {
                foreach ($constructor->getParameters() as $param) {
                    $pType = $param->getType();
                    $pTypeName = $pType instanceof \ReflectionNamedType ? $pType->getName() : 'mixed';
                    $params[] = sprintf(
                        '%s $\%s\%s',
                        $pTypeName,
                        $param->getName(),
                        $param->isOptional() ? ' [optionnel]' : ' [requis]'
                    );
                }
            }

            $publicMethods = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor() && !str_starts_with($method->getName(), '__')) {
                    $publicMethods[] = $method->getName();
                }
            }

            return sprintf(
                "INDICE DE RÉFLEXION PHP CRITIQUE (OBJET IMMUABLE / DTO - MÉTHODE INEXISTANTE) :\n"
                . "Erreur fatale : Tu tentes d'appeler la méthode inexistante `%s()` sur la classe `%s`.\n"
                . "❌ **Rappel** : Les DTOs et Value Objects sont immuables. Ils n'ont **aucun** setter (`set*`) ni méthode fluent (`with*`).\n\n"
                . "✔️ **Signature exacte et constructeur requis (extraits par Réflexion)** :\n"
                . "- **Constructeur** : `new \\%s(%s)`\n"
                . "- **Méthodes publiques réelles autorisées** : `%s`\n\n"
                . "Corrige ton code en supprimant l'appel à `%s()` et en passant toutes les valeurs directement et positionnellement dans le constructeur `new \\%s(...)`.",
                $methodName,
                $className,
                $className,
                !empty($params) ? implode(', ', $params) : 'Aucun argument',
                implode('(), ', $publicMethods) . '()',
                $methodName,
                ltrim($className, '\\')
            );
        } catch (\Exception $e) {
            return null;
        }
    }
}