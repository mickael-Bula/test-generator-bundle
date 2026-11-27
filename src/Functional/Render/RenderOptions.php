<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Render;

final readonly class RenderOptions
{
    /**
     * @param string      $namespace  namespace de la classe générée (ex. App\Tests\Dynamic\Functional)
     * @param string      $baseClass  FQCN de la classe de base E2E de l'hôte (ex. App\Tests\E2E\E2ETest)
     * @param string|null $className  nom de la classe ; dérivé du titre du scénario s'il est absent
     * @param string      $classSuffix suffixe ajouté au titre en PascalCase quand le nom est dérivé (ex. « Test »)
     */
    public function __construct(
        public string $namespace,
        public string $baseClass,
        public ?string $className = null,
        public string $classSuffix = 'Test',
    ) {
    }
}
