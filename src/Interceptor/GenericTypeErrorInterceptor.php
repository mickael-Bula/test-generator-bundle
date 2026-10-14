<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class GenericTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'TypeError:') && str_contains($errorOutput, 'given');
    }

    public function intercept(string $errorOutput): ?string
    {
        // Capture n'importe quel TypeError du type : Class::method(): Argument #X ($arg) must be of type T, [type] given
        if (!preg_match(
            '/TypeError:\s+([^:]+)::([^(]+)\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+([a-zA-Z_]+)\s+given/i',
            $errorOutput,$matches
        )) {
            return null;
        }

        $className = $matches[1];
        $methodName = $matches[2];
        $argPosition = $matches[3];
        $argName = $matches[4];
        $expectedType = trim($matches[5]);
        $givenType = $matches[6];

        $contextAdvice = sprintf(
            "L'argument #%s (`\$%s`) a reçu un type `%s`, alors que la signature exige strictement un type `%s`.",
            $argPosition,
            $argName,
            $givenType,
            $expectedType
        );

        // Affinement intelligent par Réflexion pour donner un conseil sur-mesure (ex : DateTime)
        try {
            $resolvedClass = $className;
            if (str_contains($className, 'MockObject_')) {
                if (preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $className,$m)) {
                    $candidate = $m[1];
                    if (class_exists($candidate) || interface_exists($candidate)) {
                        $resolvedClass = $candidate;
                    }
                }
            }

            if (class_exists($resolvedClass) || interface_exists($resolvedClass)) {
                $refClass = new \ReflectionClass($resolvedClass);
                if ($refClass->hasMethod($methodName)) {
                    $methodRef = $refClass->getMethod($methodName);
                    $params = $methodRef->getParameters();

                    if (isset($params[$argPosition - 1])) {
                        $paramRef = $params[$argPosition - 1];
                        $typeRef = $paramRef->getType();

                        if ($typeRef instanceof \ReflectionNamedType) {
                            $typeName = $typeRef->getName();
                            if (
                                'DateTimeInterface' === $typeName
                                || '\DateTimeInterface' === $typeName
                                || is_subclass_of($typeName, \DateTimeInterface::class)
                            ) {
                                $contextAdvice .= "\n✔️ **Conseil pour les dates** : Passe une instance valide, "
                                    . "par exemple `new \\DateTimeImmutable('now')` au lieu d'un nombre ou d'une chaîne.";
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback silencieux
        }

        return sprintf(
            "INDICE DE TYPAGE STRICT (ERREUR DE TYPE INCOMPATIBLE) :\n"
            . "La méthode ou le constructeur `%s::%s()` a rejeté l'argument `\$%s` (#%s).\n"
            . "Règle fondamentale PHP 8+ : Le type fourni ne correspond pas à la signature de la méthode.\n"
            . "Diagnostic : %s",
            $className,
            $methodName,
            $argName,
            $argPosition,
            $contextAdvice
        );
    }
}