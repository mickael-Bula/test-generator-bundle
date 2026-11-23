<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Exception;

final class ScenarioParseException extends \InvalidArgumentException
{
    public static function atLine(int $line, string $message, string $raw = ''): self
    {
        $suffix = '' === $raw ? '' : sprintf(' (« %s »)', $raw);

        return new self(sprintf('Ligne %d : %s%s', $line, $message, $suffix));
    }
}
