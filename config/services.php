<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\ModelCatalog\PermissiveModelCatalog;

use Symfony\Component\DependencyInjection\ChildDefinition;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Chargement automatique des classes du bundle
    $services->load('Mika\\TestGeneratorBundle\\', '../src/*')
        ->exclude('../src/{DependencyInjection,Dto,Enum,Exception,Resources,TestGeneratorBundle.php}');

    // Enregistrement explicite du catalogue permissif
    $services->set(PermissiveModelCatalog::class);

    // Attribue automatiquement le tag 'mika_test_generator.prompt_builder'
    // à n'importe quelle classe annotée avec #[AsTestPromptBuilder]
    $container->services()
        ->registerAttributeForAutoconfiguration(
            AsTestPromptBuilder::class,
            static function (ChildDefinition $definition, AsTestPromptBuilder $attribute): void {
                $definition->addTag('mika_test_generator.prompt_builder', [
                    'type' => $attribute->type,
                ]);
            }
        );
};