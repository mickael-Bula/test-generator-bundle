<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\RepoMap\CachedRepoMapBuilder;
use Mika\TestGeneratorBundle\Resolver\SkillResolver;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @noinspection PhpUnused
 */
#[AsTestPromptBuilder(type: 'unit')]
readonly class UnitTestPromptBuilder implements TestPromptBuilderInterface
{
    public function __construct(
        private CachedRepoMapBuilder $repoMapBuilder,
        private SkillResolver $skillResolver,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'unit' === $type;
    }

    public function buildPrompt(
        string $classCode,
        string $fqcn,
        string $className,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
        ?string $provider = null,
    ): array {
        // 1. Résolution des skills applicables
        $skillsPrompt = $this->skillResolver->resolveForClass(
            fqcn: $fqcn,
            classCode: $classCode,
        );

        return [
            'system' => $this->buildSystemMessage(
                skillsPrompt: $skillsPrompt,
                provider: $provider,
            ),
            'user' => $this->buildUserMessage(
                classCode: $classCode,
                className: $className,
                methodName: $methodName,
                existingTestCode: $existingTestCode,
                specContent: $specContent,
            ),
        ];
    }

    /**
     * Construit le prompt système global en y injectant le Repo-Map et éventuellement les consignes pour Ollama.
     * Utilisation de <<<'TEXT' (NOWDOC) pour que PHP traite tout le bloc de texte
     * comme une chaîne de caractères strictement littérale : pas d'interprétation des variables.
     * Tout ce qui commence par un $ est ignoré par le parseur PHP.
     *
     * Sans les guillemets (HEREDOC), le parser PHP interprête
     * ce qui commence par $ comme une variable et le traite comme une expression.
     *
     * @throws \RuntimeException
     */
    private function buildSystemMessage(?string $skillsPrompt = null, ?string $provider = null): string
    {
        $normalizedProvider = null !== $provider ? strtolower(trim($provider)) : null;

        $systemMessage = $this->getSystemMessage($normalizedProvider);

        try {
            $repoMap = $this->repoMapBuilder->buildMap($this->projectDir . '/src');
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
                . "- Utilise obligatoirement cette cartographie pour vérifier les namespaces exacts, les méthodes et les types de retour des classes dépendantes lors de la création de mocks.\n"
                . '- Ne devine pas les signatures des méthodes externes si elles sont présentes dans la repo-map.';
        }

        $systemMessage .= "\n\n" . <<<'TEXT'
RÈGLE D'INFÉRENCE DES DÉPENDANCES ET MOCKS :
- Analyse le constructeur (__construct) de la classe cible présente dans la Repo-Map.
- Si le constructeur requiert des services ou interfaces :
  1. Instancie automatiquement les mocks PHPUnit ($this->createMock(...)) dans la méthode setUp().
  2. Injecte ces mocks lors de l'instanciation de la classe à tester dans setUp().
  3. Dans chaque scénario BDD, configure les comportements de ces mocks (expects(), willReturn()) selon les besoins du test.
- Si la classe à tester ne requiert aucun mock (ou uniquement des scalaires/primitifs) :
  - Si la classe conserve un état constant (ex: Service, Calculator), tu peux l'instancier dans la méthode setUp().
  - POUR LES DTO, VALUE OBJECTS ET MODÈLES SANS DÉPENDANCES : N'écris NI propriété de classe, NI méthode setUp().
    Instancie directement la classe cible dans chaque méthode de test sous la section // ÉTANT DONNÉ.
- Ne demande pas à la spécification BDD de lister les mocks techniques : déduis-les toi-même à partir de la Repo-Map.
TEXT;

        $systemMessage .= "\n\n" . <<<'TEXT'
EXIGENCES STRICTES DE QUALITÉ ET STYLE :

1. NORMES PHPUNIT 10 & PHP 8 (OBLIGATOIRE) :
   - Ajoute OBLIGATOIREMENT #[CoversClass(NomDeLaClasse::class)] sur la classe de test.
   - Ajoute OBLIGATOIREMENT #[Test] sur chaque méthode de test.
   - Importe toujours les attributs :
     use PHPUnit\Framework\Attributes\CoversClass;
     use PHPUnit\Framework\Attributes\Test;
   - La méthode setUp() s'utilise de manière standard SANS AUCUN attribut (protected function setUp(): void).
   - Toutes les méthodes de test et setUp() doivent avoir le type de retour : void.
   - INTERDICTION STRICTE d'utiliser des blocs PHPDoc (/** ... */) sur la classe ou les méthodes.
   - Pour les vérifications booléennes, utilise assertTrue($condition) ou assertFalse($condition) au lieu de assertSame(true, $condition).

2. NETTOYAGE D'ENVIRONNEMENT ET MANIPULATION DU FILESYSTEM (tearDown) :
   - Si des tests écrivent dans le système de fichiers réel ou créent un dépôt Git temporaire (via `git init`), la méthode `tearDown()` doit impérativement reconfigurer les permissions récursives avant la suppression pour éviter les blocages de droits sous Windows :
     protected function tearDown(): void
     {
         if ($this->filesystem->exists($this->tempDir)) {
             $this->filesystem->chmod($this->tempDir, 0777, 0000, true);
             $this->filesystem->remove($this->tempDir);
         }
     }

3. RÈGLES STRICTES SUR LES COMMENTAIRES ET ASSERTIONS :
   - AUCUN commentaire de texte libre ou explicatif n'est autorisé dans tout le fichier (ni dans setUp(), ni dans les méthodes de test).
   - Ne recopie JAMAIS les descriptions, phrases ou détails des scénarios BDD de l'invite dans le code PHP.
   - Les SEULES lignes de commentaire autorisées dans TOUT LE FICHIER sont STRICTEMENT ces 3 balises courtes, isolées sur leur propre ligne :
     // ÉTANT DONNÉ
     // QUAND
     // ALORS
   - Il est STRICTEMENT INTERDIT d'écrire quoi que ce soit sur la même ligne après ces balises (ex: INTERDIT d'écrire "// ÉTANT DONNÉ un montant HT...").
   - Une seule assertion par scénario BDD : teste UNIQUEMENT la valeur de retour finale ou l'exception avec assertSame().
   - N'ajoute AUCUN message d'erreur personnalisé en 3e argument de assertSame() (ex: fais $this->assertSame($expected, $actual); uniquement).
   - CAS PARTICULIER DES EXCEPTIONS : Lorsqu'un test vérifie le levé d'une exception via $this->expectException(...) :
    1. Place l'enregistrement de l'attente ($this->expectException(...)) sous la section // ALORS.
    2. Place l'appel de la méthode qui déclenche l'exception sous la section // QUAND, en TOUT DERNIER.
    Exemple exact :
      // ÉTANT DONNÉ
      $invalidValue = -1;

      // ALORS
      $this->expectException(\InvalidArgumentException::class);

      // QUAND
      $this->service->doSomething($invalidValue);
TEXT;

        if (null !== $skillsPrompt) {
            $systemMessage .= "\n\n" . $skillsPrompt;
        }

        return $systemMessage;
    }

    /**
     * Construit le message utilisateur avec le code à tester et les contraintes métier.
     */
    private function buildUserMessage(
        string $classCode,
        string $className,
        ?string $methodName,
        ?string $existingTestCode,
        ?string $specContent,
    ): string {
        $testClassName = "{$className}DynamicTest";

        $message = "Génère le code du test PHPUnit pour la classe $className suivante :\n\n$classCode\n\n";
        $message .= "CONFIGURATION OBLIGATOIRE DU FICHIER DE TEST :\n";
        $message .= "- Espace de noms (namespace) : App\\Tests\\Dynamic\n";
        $message .= "- Nom de la classe de test : $testClassName\n";

        if (null !== $specContent) {
            $trimmedSpec = trim($specContent);
            $message .= "\n\nSPÉCIFICATIONS DES SCÉNARIOS (FORMAT BDD) :\n";
            $message .= "Voici le cahier des charges des tests rédigé par l'utilisateur :\n";
            $message .= "```markdown\n$trimmedSpec\n```\n\n";
            $message .= "CONSIGNES DE TRADUCTION BDD -> PHPUNIT :\n";
            $message .= "- Génère exactement une méthode de test par scénario BDD décrit ci-dessus.\n";
            $message .= "- Nomme chaque méthode de test de façon explicite d'après le titre du scénario (ex: `testCalculateVatAmountWithNegativeAmountThrowsException`).\n";
            $message .= "- Structure l'intérieur de chaque méthode selon le pattern Arrange/Act/Assert en traduisant fidèlement le 'Étant donné' / 'Lorsque' / 'Alors'.\n";
        }

        if (null !== $existingTestCode) {
            $message .= "\nUN FICHIER DE TEST EXISTE DÉJÀ POUR CETTE CLASSE !\n";
            $message .= "Tu dois impérativement FUSIONNER tes nouveaux tests avec le code existant fourni ci-dessous.\n";
            $message .= "Consignes de fusion :\n";

            if (null !== $methodName) {
                $message .= "- **Règle d'Idempotence (Priorité Haute) :** Inspecte minutieusement le code existant ci-dessous. Si des méthodes de test couvrant déjà spécifiquement la méthode `$methodName()` sont présentes :\n";
                $message .= "  a. NE DUPLIQUE PAS les tests. N'ajoute pas de méthodes ayant le même but ou des noms redondants.\n";
                $message .= "  b. Évalue si tes nouvelles propositions de tests apportent une réelle valeur ajoutée (ex. un cas limite oublié). Si oui, mets à jour ou remplace proprement les tests existants de cette méthode.\n";
                $message .= "  c. Si les tests existants pour cette méthode sont déjà complets et optimaux, renvoie le fichier d'origine sans le modifier inutilement.\n";
                $message .= "- **Pour les autres méthodes :** Ne supprime et ne modifie AUCUN des tests existants qui concernent d'autres méthodes de la classe.\n";
            } else {
                $message .= "- Ne supprime et ne modifie AUCUN des tests existants.\n";
            }

            $message .= "- S'il s'agit de nouveaux scénarios à ajouter, insère la ou les nouvelles méthodes de test à la suite.\n";
            $message .= "- Si nécessaire, fusionne proprement le contenu de la méthode `setUp()` sans casser l'existant.\n";
            $message .= "- Combine les déclarations `use` en haut du fichier si tu ajoutes de nouvelles dépendances.\n";
            $message .= "- Conserve temporairement la configuration de classe exigée (class $testClassName).\n\n";
            $message .= "Voici le code du test existant à enrichir :\n```php\n$existingTestCode\n```\n";
        } else {
            $message .= "\nGénère un nouveau fichier de test complet à partir de zéro.\n";

            if (null !== $methodName) {
                $message .= "\nATTENTION : Concentre-toi PRIORITAIREMENT et UNIQUEMENT sur les scénarios de test pour la méthode \"$methodName()\". ";
                $message .= "Ne génère pas de tests pour les autres méthodes afin de rester concis.\n";
            }
        }

        return $message;
    }

    private function getSystemMessage(string $normalizedProvider): string
    {
        if ('ollama' === $normalizedProvider) {
            return <<<'TEXT'
Tu es un expert PHPUnit 10+ et Symfony. Ton rôle est de générer un fichier de test unitaire complet, propre et exécutable.

RÈGLES DE SORTIE STRICTES (MODE CODE PHP PUR) :
1. Génère EXCLUSIVEMENT du code PHP valide et immédiatement exécutable par PHPUnit.
2. Ta réponse DOIT commencer directement par la balise <?php (aucun texte de présentation avant).
3. N'utilise AUCUN format JSON.
4. N'ajoute AUCUN texte explicatif, AUCUNE introduction et AUCUNE balise Markdown (ne mets pas ```php ... ``` autour du code).

RÈGLES D'IMPORTATION DES ATTRIBUTS (CRITIQUE) :
1. Tu DOIS IMPÉRATIVEMENT inclure ces deux lignes d'import en haut du fichier avec les autres 'use' :
   use PHPUnit\Framework\Attributes\CoversClass;
   use PHPUnit\Framework\Attributes\Test;
2. Ne laisse JAMAIS un attribut PHPUnit (#[CoversClass] ou #[Test]) sans son instruction 'use' correspondante.
TEXT;
        }

        return <<<'TEXT'
Tu es un expert PHPUnit 10+ et Symfony. Ton rôle est de générer un fichier de test unitaire complet, propre et exécutable.

FORMAT DE RÉPONSE OBLIGATOIRE :
- Réponds EXCLUSIVEMENT sous la forme d'un objet JSON valide contenant une seule clé nommée 'test_code'.
- La valeur de 'test_code' doit être une chaîne de caractères contenant l'intégralité du code PHP (commençant par <?php).
- Ne mets AUCUN balisage Markdown (ex : ```php) à l'intérieur de la valeur JSON.
TEXT;
    }
}
