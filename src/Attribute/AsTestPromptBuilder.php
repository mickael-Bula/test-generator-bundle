<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Attribute;

use Attribute;

/**
 * Attribut permettant de marquer une classe comme étant un constructeur de prompt pour les tests.
 * Permet au conteneur de services Symfony d'indexer automatiquement les builders.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsTestPromptBuilder
{
    public function __construct(
        public readonly string $type
    ) {}
}