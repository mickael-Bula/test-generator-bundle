<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class DateTimeTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'TypeError:')
            && (str_contains($errorOutput, 'DateTimeInterface') || str_contains($errorOutput, 'DateTime'));
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(
            '/TypeError:\s+([^:]+)::([^(]+)\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type/i',
            $errorOutput,
            $matches
        )) {
            return null;
        }

        $className = $matches[1];
        $methodName = $matches[2];
        $argPosition = $matches[3];
        $argName = $matches[4];

        return sprintf(
            "INDICE DE TYPAGE SPÉCIALISÉ (ERREUR DE DATE) :\n"
            . "La méthode ou le constructeur `%s::%s()` a rejeté l'argument `\$%s` (#%s) car il attend une instance de type date (`DateTimeInterface`).\n\n"
            . "✔️ **Solution obligatoire** :\n"
            . "Remplace la valeur fournie par un objet valide, par exemple :\n"
            . "```php\n"
            . "new \\DateTimeImmutable('now')\n"
            . "```\n"
            . "Ne passe jamais de nombres, de strings brutes ou de `null` pour un argument de type date strict.",
            $className,
            $methodName,
            $argName,
            $argPosition
        );
    }
}