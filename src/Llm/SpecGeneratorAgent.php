<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Mika\TestGeneratorBundle\PromptBuilder\SpecPromptBuilder;

readonly class SpecGeneratorAgent
{
    public function __construct(
        private LlmClientFactory $llmFactory,
        private SpecPromptBuilder $specPromptBuilder,
    ) {
    }

    /**
     * Analyse le code source d'une classe et génère la matrice de cas de test au format JSON.
     *
     * @param string      $classCode  Le code source de la classe PHP à analyser
     * @param string      $fqcn       Le FQCN de la classe (ex : App\Service\VatCalculator)
     * @param string|null $methodName Le nom d'une méthode spécifique (facultatif)
     * @param string|null $model      Le modèle LLM à utiliser (null pour le modèle par défaut)
     * @param string|null $provider   Le provider LLM à utiliser (null pour le provider par défaut)
     *
     * @throws \RuntimeException       Si le LLM échoue ou si le JSON retourné est invalide
     * @throws TestGenerationException
     */
    public function generateSpec(
        string $classCode,
        string $fqcn,
        ?string $methodName = null,
        ?string $type = 'unit',
        ?string $model = null,
        ?string $provider = null,
    ): SpecResultDto {
        // Résolution du client et du modèle via la factory du bundle
        $client = $this->llmFactory->getClient($provider);
        $targetModel = $model ?? $this->llmFactory->getDefaultModel();

        $messages = $this->specPromptBuilder->buildMessages($classCode, $fqcn, $methodName, $type);

        // Appel du LLM
        return $client->callForSpec($messages, $targetModel);
    }
}
