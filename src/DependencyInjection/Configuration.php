<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('test_generator');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('default_provider')
                    ->defaultValue('%env(default::LLM_PROVIDER)%')
                ->end()
                ->scalarNode('default_model')
                    ->defaultValue('%env(default::LLM_MODEL)%')
                ->end()
                ->scalarNode('project_dir')
                    ->defaultValue('%kernel.project_dir%')
                ->end()
            ->end();

        return $treeBuilder;
    }
}