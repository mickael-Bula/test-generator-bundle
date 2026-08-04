<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Mika\TestGeneratorBundle\Dto\GeneratedTestResult;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Mika\TestGeneratorBundle\Util\JsonSanitizer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
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
        private readonly JsonSanitizer $jsonSanitizer,
        private ?SerializerInterface $serializer = null,
        private readonly string $defaultProvider = 'gemini',
        private readonly string $defaultModel = 'gemini-2.5-flash-lite',
    ) {
        // Fallback autonome : instanciation manuelle d'un Serializer compatible avec les attributs PHP
        // au cas où l'application hôte n'enregistre pas de SerializerInterface dans le conteneur DI.
        if (null === $this->serializer) {
            $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
            $metadataAwareNameConverter = new MetadataAwareNameConverter($classMetadataFactory);

            $normalizer = new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: $metadataAwareNameConverter
            );

            $this->serializer = new Serializer([$normalizer], [new JsonEncoder()]);
        }
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

            // 3. Nettoyage du JSON
            $jsonString = $this->jsonSanitizer->sanitizeLlmJsonResponse($rawContent);

            // Tentative de désérialisation
            try {
                /** @var GeneratedTestResult $testResult */
                $testResult = $this->serializer->deserialize($jsonString, GeneratedTestResult::class, 'json');

                return $testResult->getCleanTestCode();
            } catch (\Throwable) {
                // Fallback direct si la désérialisation échoue
                $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data) && isset($data['test_code'])) {
                    $testResult = new GeneratedTestResult((string) $data['test_code']);

                    return $testResult->getCleanTestCode();
                }

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
