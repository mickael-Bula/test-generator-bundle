<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\RepoMap\CachedRepoMapBuilder;
use Mika\TestGeneratorBundle\Resolver\FixerSkillResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prompt Builder spécialisé dans la correction ciblée (Fixer) de tests PHPUnit défaillants.
 *
 * @noinspection PhpUnused
 */
#[AsTestPromptBuilder(type: 'fixer')]
readonly class PhpUnitFixerPromptBuilder implements TestPromptBuilderInterface
{
    use RepoMapSystemMessageTrait;

    public function __construct(
        private CachedRepoMapBuilder $repoMapBuilder,
        private FixerSkillResolver $fixerSkillResolver,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'fixer' === $type;
    }

    /**
     * @param string      $classCode        Code source de la classe testée
     * @param string      $filePath         Chemin du fichier source
     * @param string      $fqcn             FQCN de la classe testée
     * @param string      $className        Nom court de la classe testée
     * @param string|null $methodName       Méthode ciblée (optionnel)
     * @param string|null $existingTestCode Le code du test qui vient d'échouer à l'exécution PHPUnit
     * @param string|null $specContent      Sortie d'erreur brute / rapport PHPUnit ($result['output'])
     * @param string|null $provider         Nom du provider LLM (ex: 'ollama')
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
        $phpUnitOutput = $specContent ?? 'Aucune sortie d\'erreur fournie.';

        // Résolution des skills applicables au code.
        $fixerSkills = $this->fixerSkillResolver->resolveFromErrorOutput($phpUnitOutput);

        return [
            'system' => $this->buildSystemMessage(
                filePath: $filePath,
                skillsPrompt: $fixerSkills,
                provider: $provider,
            ),
            'user' => $this->buildUserMessage(
                classCode: $classCode,
                className: $className,
                failedTestCode: $existingTestCode ?? '',
                phpUnitOutput: $specContent ?? 'Aucune sortie d\'erreur fournie.',
            ),
        ];
    }

    /**
     * Construit le prompt système spécifique au rôle de Fixer.
     *
     * @throws \RuntimeException
     */
    private function buildSystemMessage(
        ?string $filePath = null,
        ?string $skillsPrompt = null,
        ?string $provider = null,
    ): string {
        $systemMessage = $this->initializeSystemMessage($filePath, $provider);

        $systemMessage .= "\n\n" . <<<'TEXT'
RÈGLES D'OR DU FIXER (EXCLUSIVEMENT DE LA CORRECTION CIBLÉE) :
1. NE RÉÉCRIS PAS LE TEST DE ZÉRO : Ne génère AUCUN nouveau scénario de test et ne supprime AUCUNE méthode de test existante.
2. CONSERVE LA STRUCTURE : Conserve exactement les mêmes méthodes de test, leur nom, leurs attributs et leur ordre.
3. ADAPTE UNIQUEMENT LES LIGNES EN ÉCHEC :
   - Analyse attentivement le rapport PHPUnit (diff Expected vs Actual).
   - Ajuste la valeur attendue ($expected) dans l'assertion pour qu'elle corresponde à ce que produit la classe ($actual).
   - Corrige la configuration des mocks/stubs (méthodes appelées, arguments, retours expects()/willReturn()).
   - Corrige les imports 'use' ou les types manquants si le test plante à l'exécution.
TEXT;

        $systemMessage .= "\n\n" . <<<'TEXT'
EXIGENCES STRICTES DE QUALITÉ ET STYLE :

1. NORMES PHPUNIT 10 & PHP 8 (OBLIGATOIRE) :
   - Conserve OBLIGATOIREMENT #[CoversClass(NomDeLaClasse::class)] sur la classe de test.
   - Conserve OBLIGATOIREMENT #[Test] sur chaque méthode de test.
   - Assure-toi que les imports suivants sont bien présents :
     use PHPUnit\Framework\Attributes\CoversClass;
     use PHPUnit\Framework\Attributes\Test;
   - La méthode setUp() s'utilise de manière standard SANS AUCUN attribut (protected function setUp(): void).
   - Toutes les méthodes de test et setUp() doivent avoir le type de retour : void.
   - INTERDICTION STRICTE d'utiliser des blocs PHPDoc (/** ... */).

2. RÈGLES STRICTES SUR LES COMMENTAIRES ET ASSERTIONS :
   - AUCUN commentaire de texte libre ou explicatif n'est autorisé dans tout le fichier.
   - Les SEULES lignes de commentaire autorisées sont STRICTEMENT ces 3 balises courtes :
     // ÉTANT DONNÉ
     // QUAND
     // ALORS
   - Il est STRICTEMENT INTERDIT d'écrire quoi que ce soit sur la même ligne après ces balises.
   - N'ajoute AUCUN message d'erreur personnalisé en 3e argument de assertSame().
TEXT;

        if (null !== $skillsPrompt) {
            $systemMessage .= "\n\n" . $skillsPrompt;
        }

        return $systemMessage;
    }

    /**
     * Construit le message utilisateur combinant le code échoué, le rapport PHPUnit et la classe source.
     */
    private function buildUserMessage(
        string $classCode,
        string $className,
        string $failedTestCode,
        string $phpUnitOutput,
    ): string {
        $message = "L'exécution du test PHPUnit pour la classe $className a ÉCHOUÉ.\n";
        $message .= "Ton unique travail est de CORRIGER ce fichier de test pour qu'il passe au vert.\n\n";

        $message .= "--- 1. RAPPORT D'ÉCHEC PHPUNIT ---\n";
        $message .= "```text\n" . trim($phpUnitOutput) . "\n```\n\n";

        $message .= "--- 2. CODE DU TEST AYANT ÉCHOUÉ (À CORRIGER) ---\n";
        $message .= "```php\n" . trim($failedTestCode) . "\n```\n\n";

        $message .= "--- 3. CODE DE LA CLASSE TESTÉE (RÉFÉRENCE ABSOLUE) ---\n";
        $message .= "```php\n" . trim($classCode) . "\n```\n\n";

        $message .= "CONSIGNES DIRECTES DE RESOLUTION :\n";
        $message .= "1. Identifie l'origine exacte de l'erreur dans la sortie PHPUnit "
            . "(diff d'assertion, type incompatible, mock mal configuré).\n";
        $message .= "2. Modifie la valeur d'assertion ou la configuration du mock dans le code du test "
            . "sans altérer la structure ni les scénarios présents.\n";
        $message .= '3. Renvoie le code PHP complet du test corrigé.';

        return $message;
    }

    private function getSystemMessage(?string $normalizedProvider): string
    {
        if ('ollama' === $normalizedProvider) {
            return <<<'TEXT'
Tu es un Expert Fixer PHPUnit 10+ et Symfony. Ton rôle est de réparer un fichier de test unitaire existant afin de le faire passer au vert.

RÈGLES DE SORTIE STRICTES (MODE CODE PHP PUR) :
1. Génère EXCLUSIVEMENT le code PHP corrigé, complet et immédiatement exécutable.
2. Ta réponse DOIT commencer directement par la balise <?php (aucun texte de présentation avant).
3. N'utilise AUCUN format JSON.
4. N'ajoute AUCUN texte explicatif, AUCUNE introduction et AUCUNE balise Markdown (ne mets pas ```php ... ``` autour du code).
TEXT;
        }

        return <<<'TEXT'
Tu es un Expert Fixer PHPUnit 10+ et Symfony. Ton rôle est de réparer un fichier de test unitaire existant afin de le faire passer au vert.

FORMAT DE RÉPONSE OBLIGATOIRE :
- Réponds EXCLUSIVEMENT sous la forme d'un objet JSON valide contenant une seule clé nommée 'test_code'.
- La valeur de 'test_code' doit être une chaîne de caractères contenant l'intégralité du code PHP corrigé (commençant par <?php).
- Ne mets AUCUN balisage Markdown (ex : ```php) à l'intérieur de la valeur JSON.
TEXT;
    }
}
