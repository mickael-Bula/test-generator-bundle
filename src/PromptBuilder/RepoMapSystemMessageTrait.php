<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Psr\Cache\InvalidArgumentException;

trait RepoMapSystemMessageTrait
{
    /**
     * Initialise le message système avec la configuration du provider et le Repo-Map.
     *
     * @throws \RuntimeException
     */
    protected function initializeSystemMessage(
        ?string $filePath = null,
        ?string $provider = null,
    ): string {
        $normalizedProvider = null !== $provider ? strtolower(trim($provider)) : null;

        $systemMessage = $this->getSystemMessage($normalizedProvider);

        try {
            // Génération de la repo-map dynamique ciblée sur le dossier fournit (src du projet hôte ou d'un vendor)
            $repoMap = $this->repoMapBuilder->buildMapForFile($filePath);
        } catch (\InvalidArgumentException|InvalidArgumentException $e) {
            $message = sprintf(
                "Impossible de générer le Repo-Map dans '%s' : %s",
                $this->projectDir . '/src', $e->getMessage()
            );

            throw new \RuntimeException($message, previous: $e);
        }

        if (!empty($repoMap)) {
            $systemMessage .= "\n\n"
                . "STRUCTURE DU PROJET (REPO-MAP) :\n"
                . "```text\n" . $repoMap . "\n```\n\n"
                . "CONSIGNES SUR LA REPO-MAP :\n"
                . '- Utilise obligatoirement cette cartographie pour vérifier les namespaces exacts, '
                . "les méthodes et les types de retour des classes dépendantes lors de la création de mocks.\n"
                . '- Ne devine pas les signatures des méthodes externes si elles sont présentes dans la repo-map.';
        }

        return $systemMessage;
    }

    /**
     *  Contrat de dépendance (Pattern Template Method) :
     *
     *  Oblige les classes utilisant ce Trait à fournir leur propre message de base
     *  (spécificités de rôle, contraintes de format de sortie JSON vs PHP pur),
     *  avant que le Trait n'y ajoute dynamiquement la Repo-Map.
     */
    abstract protected function getSystemMessage(?string $normalizedProvider): string;
}
