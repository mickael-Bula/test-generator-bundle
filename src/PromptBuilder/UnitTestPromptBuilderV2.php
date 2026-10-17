<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\Extractor\TargetedContextExtractor;
use Mika\TestGeneratorBundle\Resolver\SkillResolver;

#[AsTestPromptBuilder(type: 'unit_v2')]
readonly class UnitTestPromptBuilderV2 implements TestPromptBuilderInterface
{
    public function __construct(
        private TargetedContextExtractor $contextExtractor,
        private SkillResolver $skillResolver,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'unit_v2' === $type;
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
        // 1. Résolution des skills applicables au code cible
        $skillsPrompt =$this->skillResolver->resolveForClass(
            fqcn: $fqcn,
            classCode: $classCode,
        );

        // 2. Extraction chirurgicale du contexte (remplace la Repo-Map)
        $targetedContext =$this->contextExtractor->extractContext($filePath,$fqcn);

        return [
            'system' => $this->buildSystemMessage(
                targetedContext: $targetedContext,
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
        string $targetedContext,
        ?string $skillsPrompt = null,
        ?string $provider = null,
    ): string {
        $systemMessage = $this->getSystemHeader($provider);

        $systemMessage .= "\n\n" . $targetedContext;

        $systemMessage .= "\n\n" . <<<'TEXT'
DIRECTIVES DE GÉNÉRATION DES TESTS UNITAIRES :

1. GESTION DES DÉPENDANCES ET MOCKS (RÈGLE ABSOLUE) :
- Analyse les dépendances du constructeur listées dans le contexte ci-dessus.
- Mocker systématiquement (via `$this->createMock(...)` dans `setUp()`)
  **TOUTES** les dépendances du constructeur qui sont des interfaces, des repositories, des managers ou des services.
- Interdiction formelle d'instancier des services, managers ou repositories avec `new`.
- Instancier directement avec `new` **uniquement** les DTOs, Value Objects et Entités pures.
- Passe les arguments des méthodes de mock individuellement (ex: `->with($arg1, $arg2)`), jamais groupés dans un tableau unique.
- Les « Autres arguments du constructeur » sont de VRAIES valeurs (tableau, scalaire, objet réel) : ne les mocke jamais.
- Pour tout objet passé au SUT ou retourné par un mock, si un paramètre est `?T` ou possède une valeur par défaut `null`,
  renseigne-le avec une valeur non nulle quand le SUT l'utilise (vois « Méthodes publiques » et « Méthodes appelées »).

2. UTILISATION OBLIGATOIRE DES FQCN (NOMS COMPLETS) :
- Pour TOUTES les classes, interfaces, entités, repositories et services de l'application utilisés dans le code 
  (`createMock()`, `new`, type hints), tu DOIS IMPÉRATIVEMENT utiliser leur chemin complet avec un antislash initial (`\Namespace\...`).
- Corrige les noms de classes manquants ou erronés : utilise toujours le FQCN complet avec antislash initial
  (`\App\...`), sans instruction `use` (elles sont ajoutées automatiquement quand le test passe).
  
3. STRUCTURE ET EXEMPLE DE CODE OBLIGATOIRE POUR LE SETUP :
- La méthode `setUp()` doit **toujours** respecter cette structure générique (en mockant l'intégralité des dépendances du constructeur) :

  protected function setUp(): void
  {
      parent::setUp();
      $this->dependencyMock =$this->createMock(\Namespace\De\Lapplication\ContratInterface::class);
      
      $this->sut = new \Namespace\De\Lapplication\TargetClass($this->dependencyMock
      );
  }

- Toutes les méthodes et `setUp()` doivent retourner `void`.
- Déclare TOUJOURS chaque propriété contenant un mock avec un type intersection :
  `private \Namespace\ContratInterface&\PHPUnit\Framework\MockObject\MockObject $dependencyMock;`
  (jamais l'interface seule : `expects()` et `method()` seraient inconnus de PHPStan).
- Pour une propriété `array` ou `iterable`, ajoute juste au-dessus `/** @var list<\Namespace\Type> */`
  (ou `iterable<\Namespace\Type>`), avec le type de valeur du paramètre correspondant du constructeur.

4. RESPECT STRICT DU CONTEXTE ET INTERDICTION D'INVENTER DES MÉTHODES :
- **Consultation obligatoire :** Appuie-toi sur les signatures réelles des méthodes publiques et des types de retours indiqués dans le contexte.
- **Interdiction d'halluciner :** N'appelle **jamais** de méthodes de type fluent (`with...()`, `set...()`, etc.) 
  si elles ne figurent pas explicitement dans les signatures fournies.
- Si un DTO ou une entité doit être configuré, passe ses valeurs **uniquement par son constructeur** (`__construct`) 
  avec les arguments positionnels/nommés réels.

5. QUALITÉ, COMMENTAIRES ET STYLE :
- Utilise obligatoirement `#[CoversClass(...)]` sur la classe et `#[Test]` sur chaque méthode.
- Aucun bloc PHPDoc (`/** ... */`) sur la classe ou les méthodes. Seule exception : un `@var` au-dessus
  d'une propriété `array` ou `iterable`, pour préciser le type de valeur (jamais pour un mock).
- Seuls les commentaires de balises BDD suivants sont autorisés (isolés sur leur propre ligne, sans texte après) :
  // ÉTANT DONNÉ
  // QUAND
  // ALORS
- Pour les exceptions (`$this->expectException`), place l'attente sous `// ALORS` et l'action qui la déclenche sous `// QUAND` en tout dernier.
6. STUBS, ASSERTIONS ET MÉTHODES TESTÉES :
- Ne configure JAMAIS de stub (`->method(...)`) dans `setUp()` : PHPUnit applique le PREMIER stub enregistré qui correspond à l'appel,
  un stub de `setUp()` masque donc ceux des tests. Pour une base partagée, crée une méthode privée `stubXxx(...)`
  (valeurs en paramètres) appelée explicitement par chaque test, avec un seul stub par méthode et par test.
- Quand le SUT lit un tableau retourné par un mock, ce tableau contient TOUTES les clés lues par le SUT.
- Teste uniquement les méthodes PUBLIQUES ; les privées sont couvertes à travers elles.
  `markTestSkipped()` et `markTestIncomplete()` sont interdits.
- Chaque test contient au moins une assertion sur un résultat observable (valeur retournée, état modifié d'un objet,
  `expects(self::exactly(n))`). Le commentaire « Aucune assertion nécessaire » est interdit.
- Écris chaque valeur attendue calculée comme une expression des données d'entrée, jamais comme un résultat calculé de tête.
- Sur une entité, n'appelle que les méthodes listées dans son blueprint.
- Les entités et DTO sont créés avec `new` et configurés par leurs vrais mutateurs. N'appelle JAMAIS `expects()` ni `method()`
  sur un objet créé avec `new`. Pour changer le résultat d'une méthode calculée (isXxx, getXxx dérivé), agis sur les données
  qu'elle lit (voir « Code réel » dans le contexte). Si aucun mutateur ne le permet, crée l'objet avec
  `$this->getMockBuilder(\Classe::class)->onlyMethods(['méthode'])->getMock()`.
TEXT;

        if (null !== $skillsPrompt && trim($skillsPrompt) !== '') {$systemMessage .= "\n\n========================================\n"
            . "💡 DIRECTIVES EXPERTES ET INDICES DE CORRECTION :\n"
            . "========================================\n"
            . $skillsPrompt;
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
        $message .= "CONFIGURATION OBLIGATOIRE POUR LA PHASE DE TEST :\n"
            . "- Namespace : `App\\Tests\\Dynamic`\n"
            . "- Nom de la classe de test : `$testClassName`\n\n";

        if (null !== $specContent) {$trimmedSpec = trim($specContent);$message .= "SPÉCIFICATIONS BDD :\n```markdown\n$trimmedSpec\n```\n";
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

    private function getSystemHeader(?string $normalizedProvider): string
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
