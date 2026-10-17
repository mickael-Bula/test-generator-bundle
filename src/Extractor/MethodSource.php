<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Extractor;

final class MethodSource
{
    /** Code d'une méthode ; null pour un simple `return $this->x;` ou si elle dépasse $maxLines lignes. */
    public static function ofComputed(\ReflectionMethod $method, int $maxLines = 12): ?string
    {
        $file = $method->getFileName();
        if (false === $file) {
            return null;
        }

        $lines = \array_slice(
            file($file, FILE_IGNORE_NEW_LINES) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );
        $source = implode("\n", $lines);

        if (\count($lines) > $maxLines || 1 === preg_match('/\{\s*return\s+\$this->\w+\s*;\s*}\s*$/s', $source)) {
            return null;
        }

        return $source;
    }
}