<?php

namespace Mika\TestGeneratorBundle\Config;

use Mika\TestGeneratorBundle\config\TestGeneratorBundle\ModelCatalog\PermissiveModelCatalog;
use Mika\TestGeneratorBundle\Service\TestGeneratorService;
use Mika\TestGeneratorBundle\Command\GenerateTestCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Chargement automatique des classes du bundle
    $services->load('Mika\\TestGeneratorBundle\\', '../src/*')
        ->exclude('../src/{DependencyInjection,Entity,Dto,TestGeneratorBundle.php}');

    // Enregistrement explicite du catalogue permissif (réutilisable OpenRouter/Ollama)
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