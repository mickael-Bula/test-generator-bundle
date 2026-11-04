<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Support;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;
use Mika\TestGeneratorBundle\Llm\LlmClientInterface;

final class ScriptedLlmClient implements LlmClientInterface
{
    public const PROVIDER = 'scripted';

    /** @var list<array<int, array{role: string, content: string}>> conversations reçues, une par appel */
    public array $conversations = [];

    /**
     * @param list<string|callable(array<int, array{role: string, content: string}>): string> $responses
     */
    public function __construct(private array $responses) {}

    public function supports(string $provider): bool
    {
        return self::PROVIDER === $provider;
    }

    public function call(array $messages, string $model): string
    {
        $this->conversations[] = $messages;

        if ([] === $this->responses) {
            throw new \LogicException(sprintf(
                'Appel LLM inattendu n°%d : plus aucune réponse préparée.',
                count($this->conversations)
                )
            );
        }

        $response = array_shift($this->responses);

        return is_callable($response) ? $response($messages) : $response;
    }

    public function callForSpec(array $messages, string $model): SpecResultDto
    {
        throw new \LogicException('callForSpec() n\'est pas utilisé dans ces tests.');
    }

    public function callCount(): int
    {
        return count($this->conversations);
    }
}
