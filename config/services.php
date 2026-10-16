<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Mika\TestGeneratorBundle\Interceptor\ErrorInterceptorInterface;
use Mika\TestGeneratorBundle\Interceptor\Support\RepoMapProvider;
use Mika\TestGeneratorBundle\Interceptor\Support\SeedExtractor;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use PhpParser\Parser;
use PhpParser\ParserFactory;
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
        ->autoconfigure()
        // Tout argument typé PhpParser\Parser d'un service de ce bundle reçoit le parseur du bundle
        ->bind(Parser::class, service('mika_test_generator.php_parser'));

    // 1. Tags automatiques appliqués à toutes les classes implémentant les interfaces du bundle
    $services->instanceof(LlmClientInterface::class)
        ->tag('mika_test_generator.llm_client');

    $services->instanceof(TestPromptBuilderInterface::class)
        ->tag('mika_test_generator.prompt_builder');

    $services->instanceof(ErrorInterceptorInterface::class)
        ->tag('mika_test_generator.error_interceptor');

    // 2. Chargement automatique de toutes les classes PHP du bundle
    $services->load('Mika\\TestGeneratorBundle\\', '../src/*')
        ->exclude([
            '../src/{DependencyInjection,Dto,Enum,Exception,Resources,TestGeneratorBundle.php}',
            // Structures de données et visiteurs créés par `new` : jamais des services
            '../src/RepoMap/{RepoMap,EdgeCollector}.php',
            '../src/Interceptor/Support/{PhpUnitFailure,TypeErrorInfo}.php',
        ]);

    // 3. Enregistrement du Registry d'intercepteurs avec l'itérateur tagué
    $services->set(ErrorInterceptorRegistry::class)
        ->arg('$interceptors', tagged_iterator('mika_test_generator.error_interceptor'));

    // 4. Configuration des clés d'environnement pour AiPlatformFactory
    $services->set(AiPlatformFactory::class)
        ->arg('$geminiKey', '%env(string:default::GEMINI_API_KEY)%')
        ->arg('$openAiKey', '%env(string:default::OPENAI_API_KEY)%')
        ->arg('$anthropicKey', '%env(string:default::ANTHROPIC_API_KEY)%')
        ->arg('$openRouterKey', '%env(string:default::OPENROUTER_API_KEY)%')
        ->arg('$ollamaUrl', '%env(string:default::OLLAMA_HOST)%');

    // 5. Enregistrement des plateformes Symfony AI pour le ServiceLocator
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

    // 6. Injection dans LlmClientFactory de l'itérateur tagué et binding explicite des variables d'environnement
    $services->set(LlmClientFactory::class)
        ->arg('$clients', tagged_iterator('mika_test_generator.llm_client'))
        ->arg('$defaultProvider', '%env(string:default::LLM_PROVIDER)%')
        ->arg('$defaultModel', '%env(string:default::LLM_MODEL)%');

    // 7. Injection des variables d'environnement dans SymfonyAiClient
    $services->set(SymfonyAiClient::class)
        ->arg('$defaultProvider', '%env(string:default::LLM_PROVIDER)%')
        ->arg('$defaultModel', '%env(string:default::LLM_MODEL)%');

    // 8. Analyse statique : parseur nikic/php-parser (id propre au bundle, pas de collision avec l'application)
    $services->set(ParserFactory::class);

    $services->set('mika_test_generator.php_parser', Parser::class)
        ->factory([service(ParserFactory::class), 'createForNewestSupportedVersion']);

    // 9. Repo-map : construite à la demande et mise en cache (CacheInterface autowiré, soit cache.app)
    $services->set(RepoMapProvider::class)
        ->arg('$srcDir', '%kernel.project_dir%/src');

    $services->set(SeedExtractor::class)
        ->arg('$rootNs', 'App\\');
};