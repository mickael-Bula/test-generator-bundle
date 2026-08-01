<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Llm;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Cette classe retourne le client et le modèle correspondant aux valeurs déclarées dans le .env.
 * le tag app.llm_client a été ajouté dans le fichier de configuration `services.yaml`
 * à toutes les classes implémentant l'interface LlmClientInterface.
 */
readonly class LlmClientFactory
{
    /**
     * @param iterable<LlmClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('app.llm_client')] private iterable $clients,
        private string $defaultProvider, // Paramètre déclaré dans le fichier services.yaml
        private string $defaultModel,
    ) {
    }

    /**
     * Retourne l'instance de LlmClientInterface correspondant au provider sélectionné dans le .env.
     */
    public function getClient(?string $provider = null): LlmClientInterface
    {
        $target = strtolower($provider ?? $this->defaultProvider);

        foreach ($this->clients as $client) {
            if ($client->supports($target)) {
                return $client;
            }
        }

        throw new \InvalidArgumentException(sprintf("Le provider '%s' n'est pas supporté ou configuré.", $target));
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }
}
