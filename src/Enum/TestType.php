<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Enum;

enum TestType: string
{
    case UNIT = 'unit';
    case FUNCTIONAL = 'functional';

    public function label(): string
    {
        return match ($this) {
            self::UNIT => 'unitaire',
            self::FUNCTIONAL => 'fonctionnel',
        };
    }

    public static function tryFromInput(string $value): self
    {
        $message = sprintf(
            'Type de test "%s" non supporté. Types valides : %s',
            $value,
            implode(', ', array_column(self::cases(), 'value'))
        );

        return self::tryFrom($value) ?? throw new \InvalidArgumentException($message);
    }
}
