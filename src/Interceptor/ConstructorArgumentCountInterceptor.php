<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class ConstructorArgumentCountInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'ArgumentCountError') && str_contains($errorOutput, '__construct()');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(
            '/(?:Too few|Too many) arguments to function ([^:]+)::__construct\(\)/i',
            $errorOutput,
            $matches)
        ) {
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
                    $type =$param->getType();
                    $typeName = $type instanceof \ReflectionNamedType
                        ? $type->getName()
                        : 'mixed';

                    $params[] = sprintf(
                        '%s $\%s\%s',
                        $typeName,
                        $param->getName(),
                        $param->isOptional() ? ' [optionnel]' : ' [requis]'
                    );
                }
            }

            return sprintf(
                "INDICE DE RÉFLEXION CONSTRUCTEUR (ERREUR DE COMPTE D'ARGUMENTS) :\n"
                . "Erreur fatale : Le constructeur de la classe `%s` a reçu un nombre incorrect d'arguments.\n\n"
                . "✔️ **Signature exacte et paramètres du constructeur (extraits par Réflexion)** :\n"
                . "- **Constructeur** : `new \\%s(%s)`\n\n"
                . "Corrige ton instanciation en passant exactement les arguments requis dans le bon ordre.",
                $className,$className,
                !empty($params) ? implode(', ', $params) : 'Aucun argument'
            );
        } catch (\Exception $e) {
            return null;
        }
    }
}