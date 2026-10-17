<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

final class CoveredClassReader
{
    public function read(string $testCode): ?string
    {
        if (1 !== preg_match('/#\[CoversClass\(\s*(?<c>\\\\?[\w\\\\]+)::class/', $testCode, $m)) {
            return null;
        }
        $name = ltrim($m['c'], '\\');
        if (str_contains($name, '\\')) {
            return $name;
        }

        return 1 === preg_match('/^use\s+(?<f>[\w\\\\]*\\\\' . preg_quote($name, '/') . ');/m', $testCode, $u) ? $u['f'] : null;
    }
}
