<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\RepoMap\CachedRepoMapBuilder;
use Mika\TestGeneratorBundle\Resolver\SkillResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @noinspection PhpUnused
 */
#[AsTestPromptBuilder(type: 'unit')]
readonly class UnitTestPromptBuilder implements TestPromptBuilderInterface
{
    use RepoMapSystemMessageTrait;

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
        string $filePath,
        string $fqcn,
        string $className,
        ?string $methodName = null,
        ?string $existingTestCode = null,
        ?string $specContent = null,
        ?string $provider = null,
    ): array {
        $skillsPrompt =$this->skillResolver->resolveForClass(
            fqcn: $fqcn,
            classCode: $classCode,
        );

        return [
            'system' => $this->buildSystemMessage(
                filePath: $filePath,
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

    private function buildSystemMessage(
        ?string $filePath = null,
        ?string $skillsPrompt = null,
        ?string $provider = null,
    ): string {
        $systemMessage =$this->initializeSystemMessage($filePath,$provider);

        $systemMessage .= "\n\n" . <<<'TEXT'
DIRECTIVES DE GÉNÉRATION DES TESTS UNITAIRES :

1. GESTION DES DÉPENDANCES ET MOCKS (RÈGLE ABSOLUE) :
- Analyse le constructeur de la classe cible via la Repo-Map.
- Mocker systématiquement (via `$this->createMock(...)` dans `setUp()`) **TOUTES** les dépendances du constructeur qui sont des interfaces, des repositories, des managers ou des services.
- Interdiction formelle d'instancier des services, managers ou repositories avec `new`.
- Instancier directement avec `new` **uniquement** les DTOs, Value Objects et Entités pures.
- Passe les arguments des méthodes de mock individuellement (ex: `->with($arg1, $arg2)`), jamais groupés dans un tableau unique.

2. UTILISATION OBLIGATOIRE DES FQCN (NOMS COMPLETS) :
- Pour TOUTES les classes, interfaces, entités, repositories et services de l'application utilisés dans le code (`createMock()`, `new`, type hints), tu DOIS IMPÉRATIVEMENT utiliser leur chemin complet avec un antislash initial (`\Namespace\...`).

3. STRUCTURE ET EXEMPLE DE CODE OBLIGATOIRE POUR LE SETUP :
- La méthode `setUp()` doit **toujours** respecter cette structure générique (en mockant l'intégralité des dépendances du constructeur de la classe à tester) :

  protected function setUp(): void
  {
      parent::setUp();
      $this->dependencyMock =$this->createMock(\Namespace\De\Lapplication\ContratInterface::class);
      
      $this->sut = new \Namespace\De\Lapplication\TargetClass($this->dependencyMock
      );
  }

- Toutes les méthodes et `setUp()` doivent retourner `void`.

4. RESPECT STRICT DE LA REPO-MAP ET INTERDICTION D'INVENTER DES MÉTHODES :
- **Consultation obligatoire :** Appuie-toi sur les signatures réelles des classes, DTOs et entités présentes dans la Repo-Map fournie.
- **Interdiction d'halluciner :** N'appelle **jamais** de méthodes de type fluent 
  (`with...()`, `set...()`, etc.) si elles ne figurent pas explicitement dans le code source de la classe ou dans la Repo-Map.
- Si un DTO ou une entité doit être configuré, passe ses valeurs **uniquement par son constructeur** (`__construct`) avec les arguments positionnels/nommés réels, 
  ou utilise uniquement les setters réels existants.

5. QUALITÉ, COMMENTAIRES ET STYLE :
- Utilise obligatoirement `#[CoversClass(...)]` sur la classe et `#[Test]` sur chaque méthode.
- Aucun bloc PHPDoc (`/** ... */`) sur la classe ou les méthodes.
- Seuls les commentaires de balises BDD suivants sont autorisés (isolés sur leur propre ligne, sans texte après) :
  // ÉTANT DONNÉ
  // QUAND
  // ALORS
- Pour les exceptions ($this->expectException), place l'attente sous // ALORS et l'action déclencheuse sous // QUAND en tout dernier.
TEXT;

        if (null !== $skillsPrompt) {
            $systemMessage .= "\n\n" . $skillsPrompt;
        }

        return $systemMessage;
    }

    private function buildUserMessage(
        string $classCode,
        string $className,
        ?string $methodName,
        ?string $existingTestCode,
        ?string $specContent,
    ): string {
        $testClassName = "{$className}DynamicTest";

        $message = "Génère le code du test PHPUnit pour la classe $className :\n\n$classCode\n\n";
        $message .= "CONFIGURATION :\n- Namespace : App\\Tests\\Dynamic\n- Classe : $testClassName\n";

        if (null !== $specContent) {$trimmedSpec = trim($specContent);$message .= "\nSPÉCIFICATIONS BDD :\n```markdown\n$trimmedSpec\n```\n";
            $message .= "Génère exactement une méthode de test par scénario en respectant Arrange/Act/Assert.\n";
        }

        if (null !== $existingTestCode) {
            $message .= "\nFusionne tes nouveaux tests avec le code existant fourni ci-dessous sans casser l'existant :\n```php\n$existingTestCode\n```\n";
        } else {
            $message .= "\nGénère un nouveau fichier de test complet.\n";
            if (null !== $methodName) {
                $message .= "Concentre-toi UNIQUEMENT sur les scénarios de la méthode \"$methodName()\".\n";
            }
        }

        return $message;
    }

    private function getSystemMessage(?string $normalizedProvider): string
    {
        if ('ollama' === $normalizedProvider) {
            return <<<'TEXT'
Tu es un expert PHPUnit et Symfony. Génère exclusivement du code PHP valide et exécutable.
Règles strictes :
1. Commence directement par la balise <?php (aucun texte ni balise Markdown autour).
2. Importe obligatoirement en haut du fichier :
   use PHPUnit\Framework\Attributes\CoversClass;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;
TEXT;
        }

        return <<<'TEXT'
Tu es un expert PHPUnit et Symfony. Réponds exclusivement en JSON valide avec la clé 'test_code' contenant le code PHP (débutant par <?php).
TEXT;
    }
}