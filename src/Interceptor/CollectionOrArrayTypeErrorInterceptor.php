<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class CollectionOrArrayTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'TypeError:')
            && (str_contains($errorOutput, 'iterable') || str_contains($errorOutput, 'array') || str_contains($errorOutput, 'Collection'));
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

        $itemTypeHint = '';

        // Analyse par Réflexion pour extraire le PHPDoc de la méthode et trouver le type des éléments attendus
        try {
            $resolvedClass = $this->resolveClassName($className);
            if (class_exists($resolvedClass) || interface_exists($resolvedClass)) {
                $refClass = new \ReflectionClass($resolvedClass);
                if ($refClass->hasMethod($methodName)) {
                    $methodRef = $refClass->getMethod($methodName);
                    $docComment = $methodRef->getDocComment();

                    if ($docComment
                        && preg_match(
                            '/@param\s+(?:iterable|array|Collection)<[a-zA-Z0-9_\\\\|]+,\s*([a-zA-Z0-9_\\\\]+)(?:>|\[])/i',
                            $docComment,
                            $docMatches
                        )
                    ) {
                        $itemClassName = $docMatches[1];
                        $itemTypeHint = sprintf(
                        "\n💡 **Indice PHPDoc détecté** : La collection/itérable doit contenir des objets de type `\\%s`. "
                        . "Assure-toi de passer un tableau contenant des instances valides de cette classe (ex: `[new \\%s(...)]`).",
                        ltrim($itemClassName, '\\'),
                        ltrim($itemClassName, '\\')
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback silencieux
        }

        return sprintf(
            "INDICE DE TYPAGE SPÉCIALISÉ (COLLECTION / TABLEAU / ITÉRABLE) :\n"
            . "La méthode ou le constructeur `%s::%s()` a rejeté l'argument `\$%s` (#%s).\n"
            . "Type attendu : `%s` | Type fourni : `%s`.\n"
            . "%s\n\n"
            . "✔️ **Solution obligatoire** :\n"
            . "Passe soit un tableau PHP classique `[]`, soit un `new \\ArrayIterator([...])` ou une `\\Doctrine\\Common\\Collections\\ArrayCollection([...])`.",
            $className,
            $methodName,
            $argName,
            $argPosition,
            $expectedType,
            $givenType,
            $itemTypeHint
        );
    }

    private function resolveClassName(string $className): string
    {
        if (str_contains($className, 'MockObject_')) {
            if (preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $className,$m)) {
                if (class_exists($m[1]) || interface_exists($m[1])) {
                    return $m[1];
                }
            }
        }
        return $className;
    }
}