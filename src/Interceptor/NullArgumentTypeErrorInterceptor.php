<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class NullArgumentTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'TypeError:') && str_contains($errorOutput, 'null given');
    }

    public function intercept(string $errorOutput): ?string
    {
        // Capture n'importe quel TypeError du type : Class::method(): Argument #X ($arg) must be of type T, null given
        if (!preg_match(
            '/TypeError:\s+([^:]+)::([^(]+)\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+null given/i',
            $errorOutput,
            $matches
        )) {
            return null;
        }

        $className = $matches[1];
        $methodName = $matches[2];
        $argPosition = (int)$matches[3];
        $argName = $matches[4];
        $expectedType = trim($matches[5]);

        $reflectionDetail = sprintf(
            "   - Cible en cause dans la SUT : variable `\$%s` (Argument #%s de `%s::%s()`, Type attendu : `%s`)",
            $argName,
            $argPosition,
            $className,
            $methodName,
            $expectedType
        );

        $dtoBlueprint = '';

        try {
            $resolvedClass = $className;
            if (str_contains($className, 'MockObject_')
                && preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $className,$m)
            ) {
                if (class_exists($m[1]) || interface_exists($m[1])) {
                    $resolvedClass = $m[1];
                }
            }

            if (class_exists($resolvedClass)) {
                $refClass = new \ReflectionClass($resolvedClass);
                if ($refClass->hasMethod($methodName)) {
                    $methodRef = $refClass->getMethod($methodName);
                    $parameters = $methodRef->getParameters();

                    // CIBLAGE PRÉCIS : On regarde l'argument exact qui a échoué (position 1-indexed -> 0-indexed)
                    if (isset($parameters[$argPosition - 1])) {
                        $targetParam = $parameters[$argPosition - 1];
                        $paramType = $targetParam->getType();

                        if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                            $dtoClass = $paramType->getName();
                            if (class_exists($dtoClass)) {
                                $dtoRef = new \ReflectionClass($dtoClass);
                                $dtoConstructor = $dtoRef->getConstructor();
                                if ($dtoConstructor) {
                                    $constructorParams = [];
                                    foreach ($dtoConstructor->getParameters() as $dtoParam) {
                                        $constructorParams[] = sprintf(
                                        '$%s%s%s',
                                        $dtoParam->getName(),
                                        $dtoParam->hasType() ? ': ' . $dtoParam->getType()->getName() : '',
                                        $dtoParam->isOptional() ? ' = ...' : ''
                                    );
                                    }
                                    $dtoBlueprint = sprintf(
                                        "\n\nBlueprint Réflexion du DTO/Objet ciblé par l'argument #%d (`%s`) :\n`public function __construct(%s)`",
                                        $argPosition,
                                        $dtoClass,
                                        implode(', ', $constructorParams
                                        )
                                    );
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback silencieux en cas d'erreur de réflexion
        }

        return sprintf(
            "INDICE DE CORRECTION GÉNÉRIQUE (TYPAGE STRICT : NULL GIVEN) :\n"
            . "Erreur fatale : La méthode `%s::%s()` a reçu la valeur `null` pour l'argument `\$%s` (Argument #%s).\n"
            . "%s"
            . "%s\n\n"
            . "✔️ **Pistes de résolution universelles dans ton test** :\n"
            . "1. **Si cette donnée provient d'un Mock** : Vérifie que tu as explicitement configuré sa valeur de retour via `->willReturn(...)` (les mocks retournent `null` par défaut).\n"
            . "2. **Si cette donnée provient d'un DTO / Objet de domaine** : Assure-toi de lui avoir fourni l'intégralité des arguments requis dans son constructeur (`new MonObjet(...)`) en respectant exactement le blueprint ci-dessus pour qu'aucun getter ne retourne `null`.",
            $className,
            $methodName,
            $argName,
            $argPosition,
            $reflectionDetail,
            $dtoBlueprint
        );
    }
}