<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class TestGeneratorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            AsTestPromptBuilder::class,
            static function (ChildDefinition $definition, AsTestPromptBuilder $attribute): void {
                $definition->addTag('mika_test_generator.prompt_builder', [
                    'type' => $attribute->type,
                ]);
            }
        );
    }

    /**
     * Retourne le chemin racine du bundle (ici le dossier parent de src/).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
