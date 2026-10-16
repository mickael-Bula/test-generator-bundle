<?php

declare (strict_types = 1);

namespace Mika\TestGeneratorBundle\Parser;

final readonly class TypeErrorInfo
{
    public function __construct(
        public string  $targetClass,
        public string  $targetMethod,
        public int     $position,
        public string  $param,
        public string  $expected,
        public string  $given,
        public ?string $file,
        public ?int    $line,
    ) {}

    public static function fromOutput(string $out): ?self
    {
        $re = '/TypeError: (?<c>[\w\\\\]+)::(?<m>\w+)\(\): Argument #(?<p>\d+) \(\$(?<n>\w+)\) '
            . 'must be of type (?<e>[^,]+), (?<g>\w+) given(?:, called in (?<f>\S+) on line (?<l>\d+))?/';

        if (!preg_match($re, $out, $m)) {
            return null;
        }
        return new self(
            $m['c'], $m['m'], (int) $m['p'], $m['n'], $m['e'], $m['g'],
            ($m['f'] ?? '') !== '' ? $m['f'] : null,
            ($m['l'] ?? '') !== '' ? (int) $m['l'] : null,
        );
    }
}
