<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;

/**
 * @noinspection PhpUnused
 */
#[AsTestPromptBuilder(type: 'functional')]
final class FunctionalTestPromptBuilder implements TestPromptBuilderInterface
{
    public function supports(string $type): bool
    {
        return 'functional' === $type;
    }

    public function buildPrompt(
        string $classCode,
        string $fqcn,
        string $className,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
    ): array {
        $systemPrompt = <<<PROMPT
Tu es un expert PHP, Symfony et PHPUnit.
Génère le fichier de test fonctionnel pour la classe fournie (ex: un Contrôleur ou une API).

Règles de génération :
1. La classe de test doit hériter de `Symfony\Bundle\FrameworkBundle\Test\WebTestCase`.
2. Utilise `static::createClient()` pour simuler les requêtes HTTP.
3. Utilise les assertions natives de Symfony (`assertResponseIsSuccessful()`, `assertResponseStatusCodeSame()`, `assertSelectorTextContains()`, etc.).
4. Applique la structure BDD avec les commentaires : // ÉTANT DONNÉ, // QUAND, // ALORS.
5. Utilise les attributs PHP 8 (`#[Test]`, `#[CoversClass]`).
6. Réponds EXCLUSIVEMENT sous la forme d'un objet JSON valide contenant une unique clé "test_code".
PROMPT;

        $userPrompt = <<<PROMPT
Génère les tests fonctionnels pour le contrôleur suivant :

{$classCode}
PROMPT;

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }
}
