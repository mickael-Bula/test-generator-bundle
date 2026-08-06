<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\AI\Platform\Platform;
use Mika\TestGeneratorBundle\Llm\SymfonyAiClient;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Llm\AiPlatformFactory;
use Mika\TestGeneratorBundle\Llm\LlmClientInterface;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // 1. Tags automatiques appliqués à toutes les classes implémentant les interfaces du bundle
    $services->instanceof(LlmClientInterface::class)
        ->tag('mika_test_generator.llm_client');

    $services->instanceof(TestPromptBuilderInterface::class)
        ->tag('mika_test_generator.prompt_builder');

    // 2. Chargement automatique de toutes les classes PHP du bundle
    $services->load('Mika\\TestGeneratorBundle\\', '../src/*')
        ->exclude('../src/{DependencyInjection,Dto,Enum,Exception,Resources,TestGeneratorBundle.php}');

    // 3. Configuration des clés d'environnement pour AiPlatformFactory
    $services->set(AiPlatformFactory::class)
        ->arg('$geminiKey', '%env(string:default::GEMINI_API_KEY)%')
        ->arg('$openAiKey', '%env(string:default::OPENAI_API_KEY)%')
        ->arg('$anthropicKey', '%env(string:default::ANTHROPIC_API_KEY)%')
        ->arg('$openRouterKey', '%env(string:default::OPENROUTER_API_KEY)%')
        ->arg('$ollamaUrl', '%env(string:default::OLLAMA_HOST)%');

    // 4. Enregistrement des plateformes Symfony AI pour le ServiceLocator
    $platforms = [
        'gemini' => 'createGeminiPlatform',
        'openai' => 'createOpenAiPlatform',
        'anthropic' => 'createAnthropicPlatform',
        'openrouter' => 'createOpenRouterPlatform',
        'ollama' => 'createOllamaPlatform',
    ];

    foreach ($platforms as $index => $method) {
        $services->set('mika_test_generator.ai_platform.' . $index, Platform::class)
            ->factory([service(AiPlatformFactory::class), $method])
            ->tag('mika_test_generator.ai_platform', ['index' => $index]);
    }

    // 5. Injection dans LlmClientFactory de l'itérateur taggué et binding explicite des variables d'environnement
    $services->set(LlmClientFactory::class)
        ->arg('$clients', tagged_iterator('mika_test_generator.llm_client'))
        ->arg('$defaultProvider', '%env(string:default::LLM_PROVIDER)%')
        ->arg('$defaultModel', '%env(string:default::LLM_MODEL)%');

    // 6. Injection des variables d'environnement dans SymfonyAiClient
    $services->set(SymfonyAiClient::class)
        ->arg('$defaultProvider', '%env(string:default::LLM_PROVIDER)%')
        ->arg('$defaultModel', '%env(string:default::LLM_MODEL)%');
};