<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;

#[AsTestPromptBuilder(type: 'fixer_v2')]
readonly class PhpUnitFixerPromptBuilderV2 implements TestPromptBuilderInterface
{
    public function __construct(
        private ErrorInterceptorRegistry $interceptorRegistry,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'fixer_v2' === $type;
    }

    public function buildPrompt(
        string $classCode,
        string $filePath,
        string $fqcn,
        string $className,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
        ?string $provider = null,
    ): array {
        $phpUnitOutput = $specContent ?? 'Aucune sortie d\'erreur fournie.';

        // Résolution des indices experts via les intercepteurs modulaires par Réflexion
        $expertHint = $this->interceptorRegistry->resolveFromErrorOutput($phpUnitOutput);

        return [
            'system' => $this->buildSystemMessage($provider, $expertHint),
            'user' => $this->buildUserMessage($classCode, $className, $existingTestCode ?? '', $phpUnitOutput),
        ];
    }

    private function buildSystemMessage(
        ?string $provider = null,
        ?string $expertHint = null,
    ): string {
        $systemMessage = $this->getSystemHeader($provider);

        $systemMessage .= "\n\n" . <<<'TEXT'
RÈGLES D'OR DU FIXER (CORRECTION CIBLÉE) :
1. NE RÉÉCRIS PAS LE TEST DE ZÉRO : ne génère aucun nouveau scénario et ne supprime aucune méthode de test,
   SAUF un test ignoré (`markTestSkipped`) qui vise une méthode privée : celui-là, supprime-le.
2. CONSERVE LA STRUCTURE : Conserve exactement les mêmes méthodes de test, leur nom, leurs attributs et leur ordre.
3. ADAPTE UNIQUEMENT LES LIGNES EN ÉCHEC :
- Analyse attentivement le rapport d'erreur / rapport PHPUnit.
- Corrige la configuration des mocks/stubs (méthodes appelées, arguments, retours expects()/willReturn()).
- Corrige les imports 'use' ou les types manquants.
TEXT;

        if (null !== $expertHint && trim($expertHint) !== '') {
            $systemMessage .= "\n\n========================================\n"
            . "💡 DIRECTIVES EXPERTES DE CORRECTION (RÉFLEXION) :\n"
            . "========================================\n"
            . $expertHint;
        }

        return $systemMessage;
    }

    private function buildUserMessage(
        string $classCode,
        string $className,
        string $failedTestCode,
        string $phpUnitOutput,
    ): string {
        $message = "L'exécution du test PHPUnit pour la classe $className a ÉCHOUÉ.\n";
        $message .= "Ton unique travail est de CORRIGER ce fichier de test pour qu'il passe au vert.\n\n";

        $message .= "--- 1. RAPPORT D'ÉCHEC / ERREUR ---\n";
        $message .= "```text\n" . trim($phpUnitOutput) . "\n```\n\n";

        $message .= "--- 2. CODE DU TEST AYANT ÉCHOUÉ (À CORRIGER) ---\n";
        $message .= "```php\n" . trim($failedTestCode) . "\n```\n\n";

        $message .= "--- 3. CODE DE LA CLASSE TESTÉE (RÉFÉRENCE ABSOLUE) ---\n";
        $message .= "```php\n" . trim($classCode) . "\n```\n\n";

        $message .= "CONSIGNES DIRECTES DE RESOLUTION :\n";
        $message .= "1. Identifie l'origine exacte de l'erreur dans la sortie fournie.\n";
        $message .= "2. Modifie la valeur d'assertion ou la configuration du mock sans altérer la structure.\n";
        $message .= '3. Renvoie le code PHP complet du test corrigé.';

        return $message;
    }

    private function getSystemHeader(?string $normalizedProvider): string
    {
        if ('ollama' === $normalizedProvider) {
            return <<<'TEXT'
Tu es un Expert Fixer PHPUnit 11+ et Symfony. Ton rôle est de réparer un fichier de test unitaire existant afin de le faire passer au vert.
Règles strictes :
1. Génère EXCLUSIVEMENT le code PHP corrigé, complet et immédiatement exécutable.
2. Commence directement par la balise <?php (aucun texte ni balise Markdown autour).
TEXT;
        }

        return <<<'TEXT'
Tu es un Expert Fixer PHPUnit 11+ et Symfony. Ton rôle est de réparer un fichier de test unitaire existant afin de le faire passer au vert.
Réponds exclusivement en JSON valide avec la clé 'test_code' contenant le code PHP corrigé (commençant par <?php).
TEXT;
    }
}