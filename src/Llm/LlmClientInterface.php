<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;
use Mika\TestGeneratorBundle\Exception\TestGenerationException;

interface LlmClientInterface
{
    /**
     * Indique si ce client gère le provider demandé (ex: 'ollama', 'openrouter').
     */
    public function supports(string $provider): bool;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function call(array $messages, string $model): string;

    /**
     * Effectue un appel LLM dédié à la génération d'une matrice de spécification (JSON).
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws TestGenerationException
     */
    public function callForSpec(array $messages, string $model): SpecResultDto;
}
