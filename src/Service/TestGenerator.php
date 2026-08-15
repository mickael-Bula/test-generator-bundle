<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;
use Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

readonly class TestGenerator
{
    private const MAX_ATTEMPT = 3;

    /**
     * @param iterable<TestPromptBuilderInterface> $promptBuilders
     */
    public function __construct(
        private LlmClientFactory $llmFactory,
        private PhpUnitTestRunner $testRunner,
        private PhpSyntaxValidator $syntaxValidator,
        #[AutowireIterator('mika_test_generator.prompt_builder')] // Récupère toutes les classes portant ce tag
        private iterable $promptBuilders,
    ) {
    }

    /**
     * Retourne la première instance avec le tag répondant au type de prompt.
     */
    private function getPromptBuilder(string $type): TestPromptBuilderInterface
    {
        foreach ($this->promptBuilders as $builder) {
            if ($builder->supports($type)) {
                return $builder;
            }
        }

        throw new \InvalidArgumentException(sprintf('Aucun prompt builder trouvé pour le type "%s".', $type));
    }

    /**
     * @throws TestCorrectionException
     * @throws \RuntimeException
     */
    public function generateForClass(
        string $classCode,
        string $filePath,
        string $fqcn,
        string $className,
        ?string $model = null,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
        ?string $type = 'unit',
        ?string $provider = null,
    ): string {
        // Sélection du builder approprié
        $builder = $this->getPromptBuilder($type);

        // Construction des messages via le builder
        $prompts = $builder->buildPrompt(
            classCode: $classCode,
            filePath: $filePath,
            fqcn: $fqcn,
            className: $className,
            methodName: $methodName,
            existingTestCode: $existingTestCode,
            specContent: $specContent,
            provider: $provider
        );

        $messages = [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']],
        ];

        // On résout le client et le modèle à l'aide de la Factory
        $client = $this->llmFactory->getClient();
        $targetModel = $model ?? $this->llmFactory->getDefaultModel();

        $attempt = 0;

        while ($attempt < self::MAX_ATTEMPT) {
            ++$attempt;

            $testCode = $client->call($messages, $targetModel);

            // 1. Validation de la syntaxe PHP (nikic/php-parser)
            $syntaxResult = $this->syntaxValidator->validate($testCode);

            if (!$syntaxResult->isValid) {
                // ÉCHEC SYNTAXIQUE : On informe le LLM directement sans appeler PHPUnit
                $errorMessage = sprintf(
                    "Une erreur de syntaxe PHP s'est produite à la ligne %d :\n%s\n\n" .
                    "Règles d'anti-crash :\n" .
                    "- N'échappe JAMAIS les variables avec des antislashs (écris \$this et non \\\$this).\n" .
                    '- Vérifie la fermeture de toutes les chaînes de caractères.',
                    $syntaxResult->errorLine ?? 0,
                    $syntaxResult->errorMessage ?? 'Syntaxe PHP invalide'
                );

                $messages[] = ['role' => 'assistant', 'content' => $testCode];
                $messages[] = [
                    'role' => 'user',
                    'content' => $errorMessage . "\n\nCorrige immédiatement la syntaxe et renvoie le code PHP complet corrigé.",
                ];

                continue;
            }

            // 2. Exécution du test PHPUnit (seulement si la syntaxe est OK)
            $result = $this->testRunner->runTest($testCode, $className);

            if ($result['success']) {
                return $testCode;
            }

            // ÉCHEC DU TEST (Erreurs d'assertions ou d'exécution PHPUnit)
            $errorMessage = sprintf(
                "L'exécution de PHPUnit a ÉCHOUÉ avec l'erreur suivante :\n\n%s\n\n" .
                " CONSIGNES DE CORRECTION :\n" .
                "1. Analyse le diff PHPUnit (--- Expected vs +++ Actual).\n" .
                "2. La valeur produite par la méthode de la classe (+ Actual) est la SEULE référence valide.\n" .
                "3. Modifie la valeur attendue (\$expected) dans ton assertion pour qu'elle corresponde EXACTEMENT à la valeur réelle (+ Actual).\n" .
                "4. Ne modifie pas la logique du test, juste les valeurs d'assertion pour qu'il passe au vert.",
                $result['output']
            );

            // 3. Enrichissement de l'historique (Partagé pour PHPUnit ET erreurs JSON).
            $messages[] = ['role' => 'assistant', 'content' => $testCode];
            $messages[] = [
                'role' => 'user',
                'content' => $errorMessage . "\n\nAnalyse ce problème d'assertion, corrige ton code et renvoie le code corrigé.",
            ];
        }

        $message = sprintf(
            'Impossible de générer un test valide pour %s après %d tentatives.',
            $className,
            self::MAX_ATTEMPT
        );
        throw new TestCorrectionException($message);
    }

    public function replaceDynamicHeadersInExistingTestCode(
        string $existingTestCode,
        string $targetNamespace,
        string $className,
    ): string {
        return str_replace(
            [
                sprintf('namespace %s;', $targetNamespace),
                sprintf('class %sTest', $className),
            ],
            [
                'namespace App\Tests\Dynamic;',
                sprintf('class %sDynamicTest', $className),
            ],
            $existingTestCode
        );
    }

    public function replaceDynamicHeadersInTestCode(string $testCode, string $targetNamespace, string $className): string
    {
        return str_replace(
            [
                'namespace App\Tests\Dynamic;',
                sprintf('class %sDynamicTest', $className),
            ],
            [
                sprintf('namespace %s;', $targetNamespace),
                sprintf('class %sTest', $className),
            ],
            $testCode
        );
    }
}
