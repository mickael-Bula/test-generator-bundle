<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Classe instanciée dans services.yaml.
 *
 * @noinspection PhpUnused
 */
class AiPlatformFactory
{
    public function __construct(
        private ?HttpClientInterface $httpClient = null,
        private readonly ?string     $anthropicKey = '',
        private readonly ?string     $openAiKey = '',
        private readonly ?string     $geminiKey = '',
        private readonly ?string     $openRouterKey = '',
        private readonly ?string     $ollamaUrl = '',
    ) {
        // Fallback si aucun service HttpClientInterface n'est injecté
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @noinspection PhpUnused
     */
    public function createGeminiPlatform(): PlatformInterface
    {
        return new Platform([
            GeminiFactory::createProvider(apiKey: $this->geminiKey, httpClient: $this->httpClient),
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function createOpenAiPlatform(): PlatformInterface
    {
        return new Platform([
            OpenAiFactory::createProvider(apiKey: $this->openAiKey, httpClient: $this->httpClient),
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function createAnthropicPlatform(): PlatformInterface
    {
        return new Platform([
            AnthropicFactory::createProvider(apiKey: $this->anthropicKey, httpClient: $this->httpClient),
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function createOpenRouterPlatform(): PlatformInterface
    {
        // 1. Catalogue permissif qui déclare tout modèle demandé comme un CompletionsModel
        $catalog = new class implements ModelCatalogInterface {
            public function supports(): bool
            {
                return true;
            }

            public function getModel(string $modelName): CompletionsModel
            {
                return new CompletionsModel($modelName);
            }

            public function getModels(): array
            {
                return [];
            }
        };

        // 2. Utilisation de la Generic Factory officielle avec l'URL OpenRouter
        return GenericFactory::createPlatform(
            baseUrl: 'https://openrouter.ai/api',
            apiKey: $this->openRouterKey ?? '',
            httpClient: $this->httpClient,
            modelCatalog: $catalog
        );
    }

    /**
     * @noinspection PhpUnused
     */
    public function createOllamaPlatform(): PlatformInterface
    {
        return new Platform([
            OllamaFactory::createProvider(endpoint: $this->ollamaUrl, httpClient: $this->httpClient),
        ]);
    }
}
