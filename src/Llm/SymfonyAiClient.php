<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Symfony\Component\Serializer\Serializer;
use Mika\TestGeneratorBundle\Dto\GeneratedTestResult;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @noinspection PhpUnused
 */
class SymfonyAiClient implements LlmClientInterface
{
    /**
     * @param ServiceLocator<PlatformInterface> $platforms
     */
    public function __construct(
        // On indexe le ServiceLocator avec la colonne "index" du tag, correspondant aux noms des providers.
        #[AutowireLocator('mika_test_generator.ai_platform', indexAttribute: 'index')]
        private readonly ServiceLocator $platforms,
        private ?SerializerInterface    $serializer = null,
        private readonly string         $defaultProvider = 'gemini',
        private readonly string         $defaultModel = 'gemini-2.5-flash-lite',
    ) {
        // Fallback automatique si aucun Serializer n'est configuré dans le conteneur
        $this->serializer = $serializer ?? new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    public function supports(string $provider): bool
    {
        return $this->platforms->has(strtolower($provider));
    }

    /**
     * @param array<array{role: string, content: string}> $messages
     *
     * @throws TestGenerationException
     */
    public function call(array $messages, string $model): string
    {
        $provider = strtolower($this->defaultProvider);
        $targetModel = '' !== trim($model) ? $model : $this->defaultModel;

        if (!$this->platforms->has($provider)) {
            throw new TestGenerationException(sprintf('Le fournisseur "%s" n\'est pas configuré dans Symfony AI.', $provider));
        }

        /** @var PlatformInterface $platform */
        $platform = $this->platforms->get($provider);

        // Conversion des messages vers le format MessageBag de Symfony AI
        $messageBag = new MessageBag();
        foreach ($messages as $msg) {
            $content = trim($msg['content']);
            if ('' === $content) {
                continue;
            }

            match ($msg['role']) {
                'system' => $messageBag->add(Message::forSystem($content)),
                'assistant' => $messageBag->add(Message::ofAssistant($content)),
                default => $messageBag->add(Message::ofUser($content)),
            };
        }

        try {
            // 1. Invocation de la plateforme
            $deferredResult = $platform->invoke($targetModel, $messageBag);

            // 2. Extraction du texte brut avec la méthode asText()
            $rawContent = $deferredResult->asText();

            // 3. Nettoyage strict des balises Markdown de début et de fin
            $jsonString = preg_replace('/^```(?:json|php)?\s*/i', '', $rawContent);
            $jsonString = preg_replace('/\s*```$/', '', $jsonString);
            $jsonString = trim($jsonString);

            // Tentative de désérialisation vers le DTO
            try {
                $testResult = $this->serializer->deserialize($jsonString, GeneratedTestResult::class, 'json');

                return $testResult->getCleanTestCode();
            } catch (\Throwable) {
                // Fallback si le LLM a répondu directement en code PHP brut au lieu du JSON
                $testResult = new GeneratedTestResult($jsonString);

                return $testResult->getCleanTestCode();
            }
        } catch (\Throwable $e) {
            $message = sprintf(
                'Erreur lors de la génération avec Symfony AI (%s/%s) : %s',
                $provider,
                $targetModel,
                $e->getMessage()
            );
            throw new TestGenerationException($message, 0, $e);
        }
    }
}
