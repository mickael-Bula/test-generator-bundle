<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\Quality\MethodPatcher;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;

#[AsTestPromptBuilder(type: 'method_fixer')]
readonly class PhpUnitMethodFixerPromptBuilder implements TestPromptBuilderInterface
{
    public function __construct(
        private ErrorInterceptorRegistry $interceptorRegistry,
        private MethodPatcher $patcher,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'method_fixer' === $type;
    }

    /**
     * Prompt de réparation d'UN test. Les paramètres du contrat servent ainsi :
     *  - $methodName       : le test à réparer (obligatoire) ;
     *  - $existingTestCode : la classe de test complète, dont on extrait le test et son environnement ;
     *  - $specContent      : le bloc de rapport de CE test (voir ReportBlocks::byTest()).
     */
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
        if (null === $methodName || '' === $methodName) {
            throw new \InvalidArgumentException('Le prompt de réparation d\'un test exige le nom du test ($methodName).');
        }

        $report = $specContent ?? "Aucune sortie d'erreur fournie.";
        $testClass = $existingTestCode ?? '';

        return [
            'system' => $this->buildSystemMessage($provider, $this->interceptorRegistry->resolveFromErrorOutput($report)),
            'user' => $this->buildUserMessage(
                $classCode,
                $className,
                $methodName,
                $this->patcher->extract($testClass, $methodName) ?? '',
                $this->patcher->environment($testClass),
                $report,
            ),
        ];
    }

    private function buildSystemMessage(?string $provider, ?string $expertHint): string
    {
        $systemMessage = $this->getSystemHeader($provider);

        $systemMessage .= "\n\n" . <<<'TEXT'
RÈGLES D'OR DU FIXER (UN SEUL TEST) :
1. Renvoie la méthode demandée, avec EXACTEMENT le même nom, et rien d'autre : aucune autre méthode, aucune propriété, aucune classe.
2. Garde le scénario annoncé par le nom du test. Corrige uniquement ce que le rapport met en cause : valeurs attendues, configuration des mocks et stubs, données d'entrée, types.
3. L'environnement de la classe de test (propriétés, setUp(), helpers, data providers) n'est PAS modifiable : n'appelle que ce qu'il contient ou ce que la classe testée expose.
4. Tu ne peux pas ajouter d'instruction `use` : désigne chaque classe par son nom complet, avec antislash initial (`\App\Entity\User`).
5. Les seuls commentaires autorisés sont `// ÉTANT DONNÉ`, `// QUAND` et `// ALORS`, seuls sur leur ligne.
TEXT;

        if (null !== $expertHint && '' !== trim($expertHint)) {
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
        string $testName,
        string $testCode,
        string $environment,
        string $report,
    ): string {
        $message = sprintf("Le test `%s` de la classe de test de `%s` a ÉCHOUÉ.\n", $testName, $className);
        $message .= "Ton unique travail est de CORRIGER cette méthode de test pour qu'elle passe au vert.\n\n";

        $message .= "--- 1. RAPPORT D'ÉCHEC (CE TEST SEULEMENT) ---\n";
        $message .= "```text\n" . trim($report) . "\n```\n\n";

        $message .= "--- 2. CODE ACTUEL DU TEST (À CORRIGER) ---\n";
        $message .= "```php\n" . trim($testCode) . "\n```\n\n";

        $message .= "--- 3. ENVIRONNEMENT DE LA CLASSE DE TEST (NON MODIFIABLE) ---\n";
        $message .= "```php\n" . ('' === trim($environment) ? '// aucun' : trim($environment)) . "\n```\n\n";

        $message .= "--- 4. CODE DE LA CLASSE TESTÉE (RÉFÉRENCE ABSOLUE) ---\n";
        $message .= "```php\n" . trim($classCode) . "\n```\n\n";

        $message .= "CONSIGNES DIRECTES DE RÉSOLUTION :\n";
        $message .= "1. Identifie l'origine exacte de l'erreur dans le rapport.\n";
        $message .= "2. Modifie la valeur d'assertion, la donnée d'entrée ou la configuration du mock, sans changer le scénario.\n";
        $message .= sprintf('3. Renvoie UNIQUEMENT la méthode `%s` corrigée, avec ses attributs.', $testName);

        return $message;
    }

    private function getSystemHeader(?string $provider): string
    {
        if ('ollama' === $provider) {
            return <<<'TEXT'
Tu es un Expert Fixer PHPUnit 11+ et Symfony. Ton rôle est de réparer UN SEUL test d'une classe de test existante afin de le faire passer au vert.
Règles strictes :
1. Génère EXCLUSIVEMENT le code de la méthode corrigée, attributs (`#[Test]`...) compris.
2. Aucune classe, aucune balise <?php, aucun texte ni balise Markdown autour : commence directement par l'attribut ou par `public function`.
TEXT;
        }

        return <<<'TEXT'
Tu es un Expert Fixer PHPUnit 11+ et Symfony. Ton rôle est de réparer UN SEUL test d'une classe de test existante afin de le faire passer au vert.
Réponds exclusivement en JSON valide avec la clé 'test_code' contenant UNIQUEMENT la méthode corrigée (attributs compris), sans classe ni balise <?php.
TEXT;
    }
}
