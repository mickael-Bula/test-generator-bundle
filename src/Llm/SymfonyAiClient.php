<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Mika\TestGeneratorBundle\Dto\GeneratedTestResult;
use Mika\TestGeneratorBundle\Dto\SpecResultDto;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Mika\TestGeneratorBundle\Serializer\SpecSerializerFactory;
use Mika\TestGeneratorBundle\Util\JsonSanitizer;
use Mika\TestGeneratorBundle\Util\PhpCodeExtractor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
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
        private (SerializerInterface&DenormalizerInterface)|null $serializer = null,
        private readonly string $defaultProvider = 'gemini',
        private readonly string $defaultModel = 'gemini-3.1-flash-lite',
    ) {
        // Si l'application hôte n'enregistre pas de Serializer, on en crée un compatible avec les attributs PHP
        $this->serializer ??= SpecSerializerFactory::create();
    }

    public function supports(string $provider): bool
    {
        return $this->platforms->has(strtolower($provider));
    }

    /**
     * Méthode privée factorisée : Prépare la requête Symfony AI et exécute l'appel LLM.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws TestGenerationException
     */
    private function executeLlmCall(array $messages, string $model): string
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

        // Invocation de la plateforme (erreurs réseau/API uniquement)
        try {
            return $platform->invoke($targetModel, $messageBag)
                ->asText();
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

    /**
     * @param array<array{role: string, content: string}> $messages
     *
     * @throws TestGenerationException
     */
    public function call(array $messages, string $model): string
    {
        $provider = strtolower($this->defaultProvider);

        if (!$this->platforms->has($provider)) {
            throw new TestGenerationException(sprintf('Le fournisseur "%s" n\'est pas configuré dans Symfony AI.', $provider));
        }

        // Exécution de l'appel LLM brut
        $rawContent = $this->executeLlmCall($messages, $model);

        // Pour Ollama, on extrait directement le code PHP sans chercher à parser du JSON (demandé dans le prompt).
        if ('ollama' === $provider) {
            return PhpCodeExtractor::extract($rawContent);
        }

        // --- TRAITEMENT JSON (CAS NOMINAL) ---

        $jsonString = $this->jsonSanitizer->sanitizeLlmJsonResponse($rawContent);

        // Tentative 1 : Désérialisation via le Serializer Symfony
        try {
            /** @var GeneratedTestResult $testResult */
            $testResult = $this->serializer->deserialize($jsonString, GeneratedTestResult::class, 'json');

            return $testResult->getCleanTestCode();
        } catch (\Throwable) {
            // On ignore l'exception pour tenter les fallbacks
        }

        // Tentative 2 : Décodage manuel via json_decode
        try {
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($data) && isset($data['test_code']) && is_string($data['test_code'])) {
                $testResult = new GeneratedTestResult($data['test_code']);

                return $testResult->getCleanTestCode();
            }
        } catch (\JsonException) {
            // Le JSON est malformé
        }

        // Tentative 3 : Extraction de la valeur "test_code" dans un JSON corrompu/tronqué
        if (preg_match('/"test_code"\s*:\s*"(.*)"\s*}\s*$/s', $jsonString, $matches)) {
            $extractedCode = stripcslashes($matches[1]);

            return (new GeneratedTestResult($extractedCode))->getCleanTestCode();
        }

        // --- FALLBACK : CAS DU PHP PUR OU DU MARKDOWN SANS ENVELOPPE JSON ---

        $trimmedContent = trim($rawContent);

        // Si emballé dans du markdown ```php ... ```
        if (str_starts_with($trimmedContent, '```')) {
            $cleanPhp = preg_replace('/^```(?:php)?\s*/i', '', $trimmedContent);
            $cleanPhp = preg_replace('/\s*```$/', '', (string) $cleanPhp);

            return (new GeneratedTestResult(trim((string) $cleanPhp)))->getCleanTestCode();
        }

        // Vrai PHP pur (commence par <?php)
        if (str_starts_with($trimmedContent, '<?php')) {
            return (new GeneratedTestResult($trimmedContent))->getCleanTestCode();
        }

        // Fallback ultime
        return (new GeneratedTestResult($rawContent))->getCleanTestCode();
    }

    /**
     * Traitement dédié à la génération de matrice de spécification (JSON).
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws TestGenerationException|ExceptionInterface
     */
    public function callForSpec(array $messages, string $model): SpecResultDto
    {
        // Exécution de l'appel LLM brut
        $rawContent = $this->executeLlmCall($messages, $model);

        // Nettoyage et assainissement du JSON
        $jsonString = $this->jsonSanitizer->sanitizeLlmJsonResponse($rawContent);

        // Correction spécifique pour les namespaces PHP (ex : "App\Service\Foo" -> "App\\Service\\Foo")
        $jsonString = $this->fixPhpNamespacesInJson($jsonString);

        // Tentative 1 : Désérialisation vers SpecResultDto via le Serializer Symfony
        try {
            /** @var SpecResultDto $specDto */
            $specDto = $this->serializer->deserialize($jsonString, SpecResultDto::class, 'json');

            return $specDto;
        } catch (\Throwable) {
            // Ignoré, on passe à la tentative manuelle
        }

        // Tentative 2 : Décodage manuel via json_decode et désérialisation manuelle depuis le tableau
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['targetClass'], $data['methods']) && is_array($data['methods'])) {
                /** @var SpecResultDto $specDto */
                $specDto = $this->serializer->denormalize($data, SpecResultDto::class);

                return $specDto;
            }
        } catch (\JsonException $e) {
            throw new TestGenerationException(sprintf("L'Agent Spec a retourné un JSON invalide : %s\nRéponse brute du LLM :\n%s", $e->getMessage(), $rawContent), 0, $e);
        }

        throw new TestGenerationException(sprintf("La réponse du LLM ne contient pas la structure de spec attendue.\nRéponse brute :\n%s", $rawContent));
    }

    /**
     * Corrige les simples backslashes dans les chaînes JSON (fréquent avec les namespaces PHP).
     */
    private function fixPhpNamespacesInJson(string $json): string
    {
        // Remplacement des \ qui ne font pas partie d'une séquence d'échappement JSON valide (\", \\, \/, \b, \f, \n, \r, \t, \u)
        return preg_replace('/(?<!\\\\)\\\\(?!["\\\\\/bfnrtu])/', '\\\\\\\\', $json);
    }
}
