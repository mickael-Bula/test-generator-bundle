<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Enum;

/**
 * Comment la cible d'une étape a été retrouvée.
 */
enum ResolutionMethod: string
{
    case Rule = 'rule';
    case Llm = 'llm';
    case Navigation = 'navigation';

    public function label(): string
    {
        return match ($this) {
            self::Rule => 'règle',
            self::Llm => 'LLM',
            self::Navigation => 'navigation',
        };
    }
}
