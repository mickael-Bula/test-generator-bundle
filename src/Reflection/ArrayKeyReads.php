<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Reflection;

final class ArrayKeyReads
{
    /**
     * @return array<string, list<string>> méthode appelée => clés de tableau lues sur son retour
     */
    public static function of(string $source): array
    {
        $keys = [];
        preg_match_all('/\$(?<var>\w+)\s*=\s*\$this->\w+->(?<method>\w+)\(/', $source, $assigns, PREG_SET_ORDER);
        foreach ($assigns as $a) {
            preg_match_all('/\$' . $a['var'] . '\[\'(?<key>\w+)\']/', $source, $reads);
            foreach ($reads['key'] as $k) {
                $keys[$a['method']][$k] = true;
            }
        }

        return array_map('array_keys', $keys);
    }
}
