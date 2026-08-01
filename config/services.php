<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Mika\TestGeneratorBundle\ModelCatalog\PermissiveModelCatalog;

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
};