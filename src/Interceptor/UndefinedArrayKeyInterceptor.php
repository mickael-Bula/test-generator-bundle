<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Reflection\ArrayKeyReads;

/** @noinspection PhpUnused */
final class UndefinedArrayKeyInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Undefined array key');
    }

    public function intercept(string $errorOutput): ?string
    {
        $file = '';
        $line = 0;

        if (preg_match('/^\d+\)\s+(?<file>\S+\.php):(?<line>\d+)\R+Undefined array key\s+"(?<key>[^"]+)"/m', $errorOutput, $matches)) {
            $file = $matches['file'];
            $line = (int) $matches['line'];
            $missingKey = $matches['key'];
        } elseif (preg_match('/Undefined array key\s+"([^"]+)"/i', $errorOutput, $matches)) {
            $missingKey = $matches[1];
        } else {
            return null;
        }

        // Toutes les clés que le SUT lit sur le même retour : sinon le LLM en ajoute une par itération
        $allKeys = '';
        if ('' !== $file && is_file($file)) {
            foreach (ArrayKeyReads::of((string) file_get_contents($file)) as $method => $keys) {
                if (\in_array($missingKey, $keys, true)) {
                    $allKeys = sprintf(
                        "\nLe SUT lit sur le retour de `%s()` les clés : %s. Le mock du test doit TOUTES les fournir.",
                        $method,
                        implode(', ', $keys)
                    );
                    break;
                }
            }
        }

        $sourceContext = '';
        $serviceDocComment = '';
        $serviceMethodSignature = '';

        if ('' !== $file && file_exists($file)) {
            $fileLines = file($file);
            if (false !== $fileLines) {
                // 1. Fenêtre de code source de la SUT autour de l'erreur (pour l'affichage informatif)
                $start = max(0, $line - 6);
                $end = min(count($fileLines),$line + 3);

                $snippet = [];
                for ($i = $start; $i <$end; $i++) {
                    $prefix = ($i + 1 === $line)
                    ? '>>> LIGNE EN ERREUR : '
                    : '                    ';
                    $snippet[] = sprintf(
                        '%d: %s%s', $i + 1, $prefix, rtrim($fileLines[$i]));
                }
                $sourceContext = implode("\n", $snippet);

                // 2. Résolution robuste par Réflexion de la SUT et de ses dépendances injectées
                try {
                    $fileContent = file_get_contents($file);
                    if (
                        preg_match('/namespace\s+([^;]+);/', $fileContent,$nsMatch)
                        && preg_match('/class\s+(\S+)/', $fileContent,$classMatch)
                    ) {
                        $sutFqcn = $nsMatch[1] . '\\' . rtrim($classMatch[1], '{');
                        if (class_exists($sutFqcn)) {
                            $sutReflection = new \ReflectionClass($sutFqcn);
                            $constructor = $sutReflection->getConstructor();

                            if ($constructor) {
                                // On analyse tous les services injectés dans le constructeur de la SUT
                                foreach ($constructor->getParameters() as $parameter) {
                                    $paramType = $parameter->getType();
                                    if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                                        $dependencyClassName =$paramType->getName();

                                        if (class_exists($dependencyClassName) || interface_exists($dependencyClassName)) {
                                            $depReflection = new \ReflectionClass($dependencyClassName);

                                            // On cherche quelle méthode de ce service documente le retour du tableau avec la clé manquante
                                            foreach ($depReflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                                                $docComment = $method->getDocComment();
                                                if ($docComment && str_contains($docComment, $missingKey)) {
                                                    $serviceDocComment = $docComment;
                                                    $serviceMethodSignature = sprintf('%s::%s()', $dependencyClassName, $method->getName());
                                                    break 2; // Trouvé ! On sort des deux boucles (avec l'argument 2).
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Fallback silencieux
                }
            }
        }

        $contextBlock = '' !== $sourceContext
            ? "\n\nExtrait du code source de la SUT :\n```php\n" . $sourceContext . "\n```\n"
            : "";

        $contractBlock = '' !== $serviceDocComment
            ? "\nContrat / PHPDoc officiel de la source responsable (`$serviceMethodSignature`) :\n```php\n" . $serviceDocComment . "\n```\n"
            : "";

        return sprintf(
            "INDICE DE CORRECTION EXPERT (AVERTISSEMENT PHP : UNDEFINED ARRAY KEY) :\n"
            . "Alerte PHP détectée : La clé de tableau `%s` est absente à la ligne %s.\n"
            . "%s"
            . "%s\n"
            . "**ACTION REQUISE DANS LE TEST** :\n"
            . "En analysant le PHPDoc du service injecté ci-dessus, tu peux identifier la méthode source et toutes les clés que le tableau doit obligatoirement contenir. "
            . "Mets à jour le `willReturn(...)` du mock de ce service dans ton test pour y inclure explicitement la clé `%s` avec une valeur valide."
            . "%s. Corrige le willReturn(...) dans la méthode de test concernée, jamais dans setUp() : un stub de setUp() masque celui des tests.",
            $missingKey,
            $line > 0 ? $line : 'inconnue',
            $contextBlock,
            $contractBlock,
            $missingKey,
            $allKeys,
        );
    }
}
