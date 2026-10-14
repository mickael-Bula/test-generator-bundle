<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class EntityOrObjectTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'TypeError:')
            && str_contains($errorOutput, 'must be of type')
            && !str_contains($errorOutput, 'DateTime')
            && !str_contains($errorOutput, 'array')
            && !str_contains($errorOutput, 'iterable');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(
            '/TypeError:\s+([^:]+)::([^(]+)\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+([a-zA-Z_]+)\s+given/i',
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
        $givenType = $matches[6];

        $cleanExpectedClass = ltrim($expectedType, '\\');
        $constructorHint = '';

        // Analyse par Réflexion de la classe ATTENDUE pour lister les arguments de son constructeur
        try {
            if (class_exists($cleanExpectedClass)) {
                $refClass = new \ReflectionClass($cleanExpectedClass);
                $constructor = $refClass->getConstructor();$ctorParams = [];

                if ($constructor) {
                    foreach ($constructor->getParameters() as $param) {
                        $pType = $param->getType();
                        $pTypeName = $pType instanceof \ReflectionNamedType ? $pType->getName() : 'mixed';
                        $ctorParams[] = sprintf(
                            '%s $\%s\%s',
                            $pTypeName,
                            $param->getName(),
                            $param->isOptional() ? ' [optionnel]' : ' [requis]'
                        );
                    }
                }

                $constructorHint = sprintf(
                    "\n💡 **Analyse de la classe attendue (`\\%s`)** :\n"
                    . "- Constructeur : `new \\%s(%s)`\n"
                    . "Tu dois instancier cet objet proprement ou le mocker via `\$this->createMock(\\%s::class)` au lieu de passer une valeur scalaire (`%s`).",
                    $cleanExpectedClass,
                    $cleanExpectedClass,
                    !empty($ctorParams) ? implode(', ', $ctorParams) : 'Aucun argument',
                    $cleanExpectedClass,
                    $givenType
                );
            }
        } catch (\Exception $e) {
            // Fallback silencieux
        }

        return sprintf(
            "INDICE DE TYPAGE SPÉCIALISÉ (OBJET / ENTITÉ ATTENDU) :\n"
            . "La méthode ou le constructeur `%s::%s()` a rejeté l'argument `\$%s` (#%s).\n"
            . "Type attendu : `%s` | Type fourni : `%s`.\n"
            . "%s",
            $className,
            $methodName,
            $argName,
            $argPosition,
            $expectedType,
            $givenType,
            $constructorHint
        );
    }
}