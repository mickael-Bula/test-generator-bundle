<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\PromptBuilder\TestPromptBuilderInterface;
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
        string $fqcn,
        string $className,
        ?string $model = null,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
        string $type = 'unit',
    ): string {
        // Sélection du builder approprié
        $builder = $this->getPromptBuilder($type);

        // Construction des messages via le builder
        $prompts = $builder->buildPrompt(
            $classCode,
            $fqcn,
            $className,
            $methodName,
            $existingTestCode,
            $specContent
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

            // Exécution du test
            $result = $this->testRunner->runTest($testCode, $className);

            if ($result['success']) {
                return $testCode;
            }

            // ÉCHEC DU TEST (Erreur PHPUnit) : on prépare le message pour la tentative suivante
            $errorMessage = sprintf("L'exécution de PHPUnit a échoué :\n\n%s", $result['output']);

            // 3. Enrichissement de l'historique (Partagé pour PHPUnit ET erreurs JSON).
            $messages[] = ['role' => 'assistant', 'content' => $testCode];
            $messages[] = [
                'role' => 'user',
                'content' => $errorMessage . "\n\nAnalyse ce problème, corrige ton code et renvoie le JSON attendu.",
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
