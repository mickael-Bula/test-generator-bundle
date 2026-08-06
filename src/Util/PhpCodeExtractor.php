<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Util;

final class PhpCodeExtractor
{
    public static function extract(string $rawContent): string
    {
        $code = trim($rawContent);

        // 1. Isolation de la partie PHP
        if (preg_match('/```(?:php)?\s*(<\?php[\s\S]*?)\s*```/i', $code, $matches)) {
            $code = trim($matches[1]);
        }

        $phpPos = strpos($code, '<?php');
        if (false !== $phpPos) {
            $code = substr($code, $phpPos);
        } else {
            $code = "<?php\n\n" . $code;
        }

        // 2. Sécurité : Injection automatique des imports d'attributs PHPUnit s'ils sont absents
        if (str_contains($code, '#[CoversClass') && !str_contains($code, 'use PHPUnit\Framework\Attributes\CoversClass;')) {
            $code = preg_replace('/(namespace\s+[^;]+;)/', "$1\n\nuse PHPUnit\Framework\Attributes\CoversClass;", $code, 1);
        }

        if (str_contains($code, '#[Test]') && !str_contains($code, 'use PHPUnit\Framework\Attributes\Test;')) {
            $code = preg_replace('/(namespace\s+[^;]+;)/', "$1\nuse PHPUnit\Framework\Attributes\Test;", $code, 1);
        }

        // 3. Si TestCase est utilisé en FQCN inline (\PHPUnit\Framework\TestCase), on le remplace par TestCase
        if (str_contains($code, 'extends \PHPUnit\Framework\TestCase')) {
            $code = str_replace('extends \PHPUnit\Framework\TestCase', 'extends TestCase', $code);
        }

        // 4. Si `use PHPUnit\Framework\TestCase;` est manquant, on l'ajoute
        if (str_contains($code, 'extends TestCase') && !str_contains($code, 'use PHPUnit\Framework\TestCase;')) {
            $code = preg_replace('/(namespace\s+[^;]+;)/', "$1\n\nuse PHPUnit\Framework\TestCase;", $code, 1);
        }

        return trim($code);
    }
}
