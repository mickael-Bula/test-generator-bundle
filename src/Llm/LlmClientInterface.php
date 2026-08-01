<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

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
}
