<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class UndefinedArrayKeyInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Undefined array key');
    }

    /**
     * Intercepte les avertissements "Undefined array key" en combinant
     * l'extrait source et la Réflexion PHP pour fournir la signature, y compris les PHPDoc
     */
    public function intercept(string $errorOutput): ?string
    {
        $file = '';
        $line = 0;

        if (preg_match(
            '/([^\r\n:]+\.php):(\d+)\s*\n\s*Undefined array key\s+"([^"]+)"/i',
            $errorOutput,
            $matches
        )) {
            $file = $matches[1];
            $line = (int)$matches[2];
            $missingKey = $matches[3];
        } elseif (preg_match('/Undefined array key\s+"([^"]+)"/i', $errorOutput,$matches)) {
            $missingKey = $matches[1];
        } else {
            return null;
        }

        $sourceContext = '';
        $serviceDocComment = '';
        $serviceMethodSignature = '';

        if ('' !== $file && file_exists($file)) {
            $fileLines = file($file);
            if (false !== $fileLines) {
                // 1. Fenêtre de code source de la SUT autour de l'erreur
                $start = max(0, $line - 6);
                $end = min(count($fileLines),$line + 3);

                $snippet = [];
                for ($i = $start; $i < $end; $i++) {
                    $prefix = ($i + 1 === $line)
                        ? '>>> LIGNE EN ERREUR : '
                        : '                    ';
                    $snippet[] = sprintf('%d: %s%s', $i + 1, $prefix, rtrim($fileLines[$i]));
                }
                $sourceContext = implode("\n", $snippet);

                // 2. Extraire la propriété/service et la méthode appelée
                for ($i = $line - 1; $i >= max(0, $line - 5); $i--) {
                    if (preg_match('/\$this->([a-zA-Z0-9_]+)->([a-zA-Z0-9_]+)\s*\(/', $fileLines[$i],$callMatch)) {
                        $serviceProperty = $callMatch[1];
                        $calledMethodName = $callMatch[2];

                        // 3. Résolution par Réflexion de la classe du service et de son PHPDoc
                        try {
                            $fileContent = file_get_contents($file);
                            if (
                                preg_match('/namespace\s+([^;]+);/', $fileContent, $nsMatch)
                                && preg_match('/class\s+(\S+)/', $fileContent, $classMatch)
                            ) {
                                $sutFqcn = $nsMatch[1] . '\\' . $classMatch[1];
                                if (class_exists($sutFqcn)) {
                                    $sutReflection = new \ReflectionClass($sutFqcn);

                                    // Cherche le type typé de la propriété dans la SUT
                                    $serviceClassName = null;
                                    if ($sutReflection->hasProperty($serviceProperty)) {
                                        $propType = $sutReflection->getProperty($serviceProperty)->getType();
                                        if ($propType instanceof \ReflectionNamedType) {
                                            $serviceClassName = $propType->getName();
                                        }
                                    }

                                    // Si on a la classe du service, on extrait son PHPDoc exact
                                    if ($serviceClassName && class_exists($serviceClassName)) {
                                        $serviceReflection = new \ReflectionClass($serviceClassName);
                                        if ($serviceReflection->hasMethod($calledMethodName)) {
                                            $methodRef = $serviceReflection->getMethod($calledMethodName);
                                            $serviceDocComment = $methodRef->getDocComment() ?: ''; // Récupère le PHPDoc
                                            $serviceMethodSignature = sprintf('%s::%s()', $serviceClassName,$calledMethodName);
                                        }
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            // On ignore en cas de non-résolution
                        }
                        break;
                    }
                }
            }
        }

        $contextBlock = '' !== $sourceContext
            ? "\n\nExtrait du code source de la SUT :\n```php\n" . $sourceContext . "\n```\n"
            : "";

        $contractBlock = '' !== $serviceDocComment
            ? "\nContrat / PHPDoc officiel de la méthode source (`$serviceMethodSignature`) :\n```php\n" . $serviceDocComment . "\n```\n"
            : "";

        return sprintf(
            "INDICE DE CORRECTION EXPERT (AVERTISSEMENT PHP : UNDEFINED ARRAY KEY) :\n"
            . "Alerte PHP détectée : La clé de tableau `%s` est absente à la ligne %s.\n"
            . "%s"
            . "%s\n"
            . "**ACTION REQUISE DANS LE TEST** :\n"
            . "En analysant le PHPDoc du service ci-dessus, tu peux voir toutes les clés que le tableau doit obligatoirement contenir. "
            . "Mets à jour le `willReturn(...)` du mock de ce service dans ton test pour y inclure explicitement la clé `%s` avec une valeur valide.",
            $missingKey,
            $line > 0 ? $line : 'inconnue',
            $contextBlock,
            $contractBlock,
            $missingKey
        );
    }
}
