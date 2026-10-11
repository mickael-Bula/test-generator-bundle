<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\PromptBuilder;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\RepoMap\CachedRepoMapBuilder;
use Mika\TestGeneratorBundle\Resolver\FixerSkillResolver;
use Psr\Cache\InvalidArgumentException;
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

        // Résolution des Skills applicables au code.
        $fixerSkills = $this->fixerSkillResolver->resolveFromErrorOutput($phpUnitOutput);

        return [
            'system' => $this->buildSystemMessage(
                filePath: $filePath,
                skillsPrompt: $fixerSkills,
                provider: $provider,
                phpUnitOutput: $phpUnitOutput,
            ),
            'user' => $this->buildUserMessage(
                classCode: $classCode,
                className: $className,
                failedTestCode: $existingTestCode ?? '',
                phpUnitOutput: $phpUnitOutput,
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
        ?string $phpUnitOutput = null,
    ): string {
        $systemMessage = $this->initializeSystemMessage($filePath, $provider);

        // --- DÉTECTIONS ET EXTRACTIONS DES INDICES CORRECTIFS ---
        if (null !== $phpUnitOutput) {
            // TODO : beaucoup de répétition du même code. À re-factoriser => transformer en Skills ?
            // Récupère la Repo-Map générée pour utilisation dans les détecteurs
            try {
                $repoMap = $this->repoMapBuilder->buildMapForFile($filePath);

                // 1. Indice Repo-Map pour les classes inconnues
                $targetedHint =$this->extractTargetedFqcnHint($phpUnitOutput,$repoMap);
                if (null !== $targetedHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $targetedHint . "\n```\n";
                }

                // 2. Indice Repo-Map pour les erreurs "Class not found" à l'instanciation
                $classNotFoundHint =$this->extractClassNotFoundErrorHint($phpUnitOutput,$repoMap);
                if (null !== $classNotFoundHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $classNotFoundHint . "\n```\n";
                }

                // 3. Indice Erreur "expects() on null"
                $expectsNullHint = $this->extractExpectsOnNullErrorHint($phpUnitOutput);
                if (null !== $expectsNullHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $expectsNullHint . "\n```\n";
                }

                // 4. Indice Erreur de compatibilité de classe anonyme (Noms courts dans les signatures)
                $anonCompatHint = $this->extractAnonymousClassCompatibilityHint($phpUnitOutput);
                if (null !== $anonCompatHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $anonCompatHint . "\n```\n";
                }

                // 5. Indice Réflexion Attribut Symfony mal instancié
                $attributeHint = $this->extractAttributeInstantiationErrorHint($phpUnitOutput);
                if (null !== $attributeHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $attributeHint . "\n```\n";
                }

                // 6. Indice Réflexion PHP pour les DTOs non mutables et leurs constructeurs
                $dtoHint = $this->extractDtoConstructorHint($phpUnitOutput);
                if (null !== $dtoHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $dtoHint . "\n```\n";
                }

                // 7. Indice Avertissement PHP (Undefined array key)
                $arrayKeyHint = $this->extractUndefinedArrayKeyHint($phpUnitOutput);
                if (null !== $arrayKeyHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $arrayKeyHint . "\n```\n";
                }

                // 8. Indice TypeError Argument Null
                $typeErrorHint = $this->extractGenericTypeErrorHint($phpUnitOutput);
                if (null !== $typeErrorHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $typeErrorHint . "\n```\n";
                }

                // 9. Indice Réflexion Constructeur (interdiction des `null` dans le constructeur)
                $constructorHint = $this->extractConstructorTypeErrorHint($phpUnitOutput);
                if (null !== $constructorHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $constructorHint . "\n```\n";
                }

                // 10. Indice lors d'un Call to undefined method expects() sur un objet non mocké
                $constructorHint = $this->extractExpectsOnNonMockErrorHint($phpUnitOutput);
                if (null !== $constructorHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $constructorHint . "\n```\n";
                }

                // 11. Indice lors d'un avertissement Risky Test lié à des attentes contradictoires
                $riskyTestHint = $this->extractRiskyTestAssertionHint($phpUnitOutput);
                if (null !== $riskyTestHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $riskyTestHint . "\n```\n";
                }

                // 12. Indice sur les attentes sur un nombre d'appels mal configuré (ex : once() pour une boucle)
                $mockCountHint = $this->extractMockCountExpectationHint($phpUnitOutput);
                if (null !== $mockCountHint) {
                    $systemMessage .= "\n\n" . "```text\n" . $mockCountHint . "\n```\n";
                }
            } catch (\Exception|InvalidArgumentException $e) {
                // On ignore silencieusement l'erreur si la Repo-Map échoue ici
            }
        }

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

1. NORMES PHPUNIT 11 & PHP 8 (OBLIGATOIRE) :
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
Tu es un Expert Fixer PHPUnit 11+ et Symfony. Ton rôle est de réparer un fichier de test unitaire existant afin de le faire passer au vert.

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

    /**
     * Extrait automatiquement le FQCN d'une classe manquante depuis la Repo-Map
     * lors d'une UnknownTypeException PHPUnit.
     */
    private function extractTargetedFqcnHint(string $phpUnitOutput, string $repoMap): ?string
    {
        if (!preg_match('/Class or interface "([^"]+)" does not exist/', $phpUnitOutput, $matches)) {
            return null;
        }

        $shortName = $matches[1];
        $foundFqcn = null;

        $lines = explode("\n", $repoMap);
        foreach ($lines as $line) {
            if (!str_contains($line, $shortName)) {
                continue;
            }

            // Nettoyage des artefacts de la Repo-Map (markdown, tirets, extensions de fichiers, etc.)
            $cleaned = trim(str_ireplace(['interface', 'class', 'trait', 'enum', '-', '->', ':', '`', '*', '|', '.php'], '', $line));
            $cleaned = trim(preg_replace('/^[\s\vert{}\-+*]+/', '',$cleaned));

            // Conversion intelligente si la Repo-Map utilise des chemins de fichiers
            $cleaned = str_replace('/', '\\',$cleaned);
            if (str_starts_with($cleaned, 'src\\')) {
                $cleaned = 'App\\' . substr($cleaned, 4);
            }

            // Recherche d'un FQCN valide se terminant par le nom court recherché
            if (preg_match('/([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*' . preg_quote($shortName, '/') . ')\b/', $cleaned,$m)) {
                $candidate = '\\' . ltrim($m[1], '\\');

                // On valide que c'est un namespace applicatif ou tiers reconnu
                if (
                    str_contains($candidate, '\\App\\')
                    || str_contains($candidate, '\\Doctrine\\')
                    || str_contains($candidate, '\\Symfony\\')
                ) {
                    $foundFqcn =$candidate;
                    break;
                }
            }
        }

        if (!$foundFqcn) {
            return sprintf(
                "INDICE DE RÉFLEXION FQCN (CLASSE INCONNUE) :\n" .
                "La classe ou l'interface `%s` est introuvable. " .
                "Utilise systématiquement son FQCN absolu complet (ex: \\App\\...\\%s).",
                $shortName,$shortName
            );
        }

        $cleanFqcn = ltrim($foundFqcn, '\\');

        return sprintf(
            "INDICE DE RÉFLEXION FQCN (NOM COURT INTERDIT - UTILISE LE FQCN) :\n"
            . "PHPUnit a levé une `UnknownTypeException` pour `%s` car tu as utilisé un nom court sans l'importer.\n\n"
            . "**SOLUTION OBLIGATOIRE** :\n"
            . "N'utilise pas de noms courts pour les types et les mocks. Utilise toujours le **FQCN absolu** complet.\n"
            . "Remplace `%s` par :\n"
            . "```php\n"
            . "\\%s\n"
            . "```\n"
            . "Exemple correct :\n"
            . "```php\n"
            . "\$this->createMock(\\%s::class);\n",
            $shortName,
            $shortName,
            $cleanFqcn,
            $cleanFqcn
        );
    }

    /**
     * Inspecte par Réflexion la classe visée pour lister ses vraies méthodes publiques
     * et son constructeur lorsque le LLM tente d'appeler une méthode inexistante (with*, set*, etc.).
     */
    private function extractDtoConstructorHint(string $phpUnitOutput): ?string
    {
        // Capture la classe et la méthode interdite (ex : Call to undefined method App\Dto\ClassName::with... ou set...)
        if (!preg_match('/Call to undefined method ([^:]+)::(with|set)/i', $phpUnitOutput,$matches)) {
            return null;
        }

        $className =$matches[1];
        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);

            // 1. Récupération des paramètres du constructeur
            $constructor = $reflection->getConstructor();
            $params = [];

            if ($constructor) {
                foreach ($constructor->getParameters() as $param) {
                    $params[] = '$' . $param->getName() . ($param->isOptional() ? ' [optionnel]' : ' [requis]');
                }
            }

            // 2. Récupération de TOUTES les vraies méthodes publiques disponibles via Réflexion
            $publicMethods = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor() && !str_starts_with($method->getName(), '__')) {
                    $publicMethods[] = $method->getName();
                }
            }

            return sprintf(
                "INDICE DE RÉFLEXION PHP CRITIQUE (OBJET IMMUABLE / DTO) :\n" .
                "Erreur fatale : Tu tentes d'appeler une méthode inexistante (`with*` ou `set*`) sur la classe `%s`.\n" .
                "❌ **Rappel** : Les DTOs et Value Objects sont immuables. Ils n'ont **aucun** setter ni méthode fluent `with*`.\n\n" .
                "✔️ **API Réelle et exhaustive disponible sur cette classe (extraite par Réflexion)** :\n" .
                "- **Constructeur** : `new \\%s(%s)`\n" .
                "- **Méthodes publiques autorisées** : `%s`\n\n" .
                "Corrige ton code en supprimant les appels à `with*` / `set*` et en passant toutes les valeurs directement dans le constructeur.",
                $className,$className,
                implode(', ', $params),
                implode('(), ', $publicMethods) . '()'
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Intercepte les avertissements PHP de type "Undefined array key"
     * pour indiquer au LLM de configurer les valeurs de retour de ses mocks.
     */
    private function extractUndefinedArrayKeyHint(string $phpUnitOutput): ?string
    {
        $file = '';
        $line = '';

        if (preg_match(
            '/([^\r\n:]+):(\d+)\s*\n\s*Undefined array key\s+"([^"]+)"/i',
            $phpUnitOutput,
            $matches
        )) {
            $file = $matches[1];
            $line = $matches[2];
            $missingKey = $matches[3];
        }
        // Fallback : si la sortie est sur une seule ligne ou format différent
        elseif (preg_match('/Undefined array key\s+"([^"]+)"/i', $phpUnitOutput,$matches)) {
            $missingKey =$matches[1];
        } else {
            return null;
        }

        $locationInfo = $file !== '' ? sprintf(" (à la ligne %s de %s)", $line, basename($file)) : "";

        return sprintf(
            "INDICE DE CORRECTION AVERTISSEMENT PHP (UNDEFINED ARRAY KEY) %s :\n"
            . "Alerte PHP détectée : La clé de tableau `%s` est introuvable lors de l'exécution.\n"
            . "Diagnostic : L'un de tes mocks ou une dépendance injectée renvoie un tableau incomplet ou vide, "
            . "alors que la SUT s'attend à y trouver la clé `%s`.\n\n"
            . "**Correction à appliquer** :\n"
            . "Mets à jour la configuration de tes mocks (via `.willReturn(...)`) "
            . "pour que les données retournées incluent explicitement la clé `%s` avec une valeur valide de test.",
            $locationInfo,
            $missingKey,
            $missingKey,
            $missingKey
        );
    }

    /**
     * Intercepte les TypeError sur les constructeurs pour interdire le passage de `null`
     * et imposer l'utilisation de mocks.
     */
    private function extractConstructorTypeErrorHint(string $phpUnitOutput): ?string
    {
        if (!preg_match(
            '/TypeError:\s+([^:]+)::__construct\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+null given/i',
            $phpUnitOutput,
            $matches)
        ) {
            return null;
        }

        $className =$matches[1];
        $argNum =$matches[2];
        $argName =$matches[3];
        $expectedType = trim($matches[4]);

        return sprintf(
            "INDICE DE RÉFLEXION CONSTRUCTEUR (CRITIQUE) :\n"
            . "Erreur de type dans le constructeur de '%s' à l'argument #%s (\$%s).\n"
            . "Il est STRICTEMENT INTERDIT de passer `null` pour un argument typé (attendu : `%s`).\n"
            . "Tu dois impérativement créer un mock complet pour cette dépendance via `$this->createMock(%s::class)` dans le `setUp()` et le passer en argument.",
            $className,
            $argNum,$argName,
            $expectedType,$expectedType
        );
    }

    /**
     * Extrait automatiquement le FQCN depuis la Repo-Map lorsqu'une erreur "Class ... not found" survient à l'instanciation.
     */
    private function extractClassNotFoundErrorHint(string $phpUnitOutput, string $repoMap): ?string
    {
        // Capture le nom de la classe dans l'erreur PHP
        if (!preg_match('/Error:\s+Class\s+"([^"]+)"\s+not found/i', $phpUnitOutput, $matches)) {
            return null;
        }

        $shortName = $matches[1];

        $lines = explode("\n", $repoMap);
        $matchingFqcns = [];

        foreach ($lines as $line) {
            // Ignore les lignes de code ou signatures
            if (
                str_contains($line, 'function')
                || str_contains($line, '(')
                || str_contains($line, ')')
                || str_contains($line, '=')
            ) {
                continue;
            }

            if (str_contains($line, $shortName)) {
                $cleaned = trim(str_ireplace(['interface', 'class', 'trait', 'enum', '-', '->', ':', '`', '*', '|'], '', $line));
                // Nettoyage de la regex sans parasites
                $cleaned = trim(preg_replace('/^[\s\-+*|]+/', '', $cleaned));

                if (
                    str_contains($cleaned, '\\')
                    && (str_ends_with($cleaned, $shortName) || str_contains($cleaned, '\\' . $shortName))
                ) {
                    $fqcn = str_starts_with($cleaned, '\\') ? $cleaned : '\\' . ltrim($cleaned, '\\');

                    if (str_contains($fqcn, 'App\\') || str_contains($fqcn, 'Doctrine\\')) {
                        $matchingFqcns[] = $fqcn;
                    }
                }
            }
        }

        $matchingFqcns = array_unique($matchingFqcns);

        if (empty($matchingFqcns)) {
            return null;
        }

        return sprintf(
            "INDICE REPO-MAP CLASS NOT FOUND (CRITIQUE) :\n"
            . "La classe instanciée '%s' est introuvable car son FQCN complet n'a pas été utilisé.\n"
            . "Le chemin exact trouvé dans la Repo-Map est : %s\n"
            . "Tu dois impérativement utiliser ce FQCN complet (ex: `new %s(...)`) ou ajouter l'instruction `use ...;` au début du fichier de test.",
            $shortName,
            reset($matchingFqcns),
            reset($matchingFqcns)
        );
    }

    /**
     * Intercepte les erreurs quand le LLM tente d'instancier manuellement un attribut Symfony (ex : TaggedIterator)
     * au lieu de passer un itérable standard.
     */
    private function extractAttributeInstantiationErrorHint(string $phpUnitOutput): ?string
    {
        if (!preg_match(
            '/TypeError:\s+(Symfony\\\Component\\DependencyInjection\\\Attribute\\\[^:]+)::__construct\(\)/i',
            $phpUnitOutput,
            $matches)
        ) {
            return null;
        }

        $attributeClass =$matches[1];

        return sprintf(
            "INDICE DE RÉFLEXION ATTRIBUT (CRITIQUE) :\n"
            . "Tu tentes d'instancier manuellement un attribut Symfony (`%s`) dans le test.\n"
            . "Les attributs PHP (`#[...]`) ne s'instancient **jamais** avec `new` dans le code de test.\n"
            . "Si le constructeur de la classe testée attend un itérable de stratégies, passe simplement un tableau vide `[]` "
            . "ou un `new \\ArrayIterator([])` directement en argument, sans utiliser de classe d'attribut Symfony.",
            $attributeClass
        );
    }

    /**
     * Intercepte universellement TOUTES les erreurs de type "null given" sur les arguments de méthodes ou constructeurs,
     * et utilise la Réflexion PHP pour générer un indice correctif agnostique.
     */
    private function extractGenericTypeErrorHint(string $phpUnitOutput): ?string
    {
        // Capture n'importe quel TypeError du type : Class::method(): Argument #X ($arg) must be of type T, null given
        if (!preg_match(
            '/TypeError:\s+([^:]+)::([^(]+)\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+null given/i',
            $phpUnitOutput,
            $matches
        )) {
            return null;
        }

        $className =$matches[1];
        $methodName =$matches[2];
        $argPosition =$matches[3];
        $argName =$matches[4];
        $expectedType = trim($matches[5]);

        $contextAdvice = "Vérifie les données transmises ou l'état de l'objet source dans ton test.";

        // Analyse par Réflexion pour affiner le conseil de manière générique
        try {
            $resolvedClass =$className;

            // Si c'est un mock PHPUnit, on extrait proprement le nom de la classe/interface d'origine
            if (str_contains($className, 'MockObject_')) {
                if (preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $className,$m)) {
                    $candidate =$m[1];
                    if (class_exists($candidate) || interface_exists($candidate)) {
                        $resolvedClass =$candidate;
                    }
                }
            }

            // Vérification de sécurité avant d'instancier ReflectionClass
            if (class_exists($resolvedClass) || interface_exists($resolvedClass)) {
                $refClass = new \ReflectionClass($resolvedClass);
                $shortName =$refClass->getShortName(); // Utilisation native de ReflectionClass

                if ($refClass->hasMethod($methodName)) {
                    if ($methodName === '__construct') {
                        $contextAdvice = sprintf(
                        "Dans l'instanciation de la SUT (`new %s(...)`), l'argument #%s (`\$%s`) a reçu `null` "
                        . "alors qu'il attend un type strict `%s`. Tu dois lui passer une instance valide (objet ou mock) et non `null`.",
                        $shortName, $argPosition,
                        $argName, $expectedType
                    );
                    } else {
                        $contextAdvice = sprintf(
                            "Lors de l'appel à la méthode `%s()`, l'argument #%s (`\$%s`) a reçu `null` (attendu : `%s`). "
                            . "Si cet argument provient d'une entité ou d'un mock, "
                            . "assure-toi que la propriété ou la valeur de retour est correctement initialisée avant l'appel.",
                            $methodName, $argPosition,
                            $argName, $expectedType
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback silencieux si la réflexion échoue
        }

        return sprintf(
            "INDICE GÉNÉRIQUE DE TYPAGE (ERREUR NULL GIVEN) :\n"
            . "La méthode ou le constructeur `%s::%s()` a rejeté l'argument `\$%s` (#%s).\n"
            . "Règle fondamentale PHP 8+ : En typage strict, **aucun** argument non-nullable ne tolère la valeur `null`.\n"
            . "Diagnostic : %s",
            $className,
            $methodName,$argName,
            $argPosition,$contextAdvice
        );
    }

    /**
     * Intercepte les erreurs fatales de compatibilité de signature dans les classes anonymes
     * (quand un nom court est utilisé au lieu d'un FQCN dans une signature d'interface).
     */
    private function extractAnonymousClassCompatibilityHint(string $phpUnitOutput): ?string
    {
        if (!preg_match(
            '/Could not check compatibility between.+because class\s+(\S+)\s+is not available/i',
            $phpUnitOutput,
            $matches
        )) {
            return null;
        }

        $missingClassName =$matches[1];

        return sprintf(
            "INDICE DE CORRECTION CLASSE ANONYME (COMPATIBILITÉ DE SIGNATURE) :\n"
            . "Erreur fatale : La classe `%s` n'est pas disponible ou résolue dans la signature de méthode de ta classe anonyme.\n"
            . "Règle PHP pour les classes anonymes implémentant une interface : "
            . "**Tous** les types d'arguments dans les signatures de méthodes (`execute`, etc.) doivent utiliser leur **FQCN complet** "
            . "(ex : `\\App\\Entity\\Position`, `\\App\\Dto\\MarketData\\CacDailyDto`).\n"
            . "Modifie tes classes anonymes pour remplacer les noms courts par leurs chemins de namespace complets dans les signatures.",
            $missingClassName
        );
    }

    private function extractExpectsOnNullErrorHint(string $phpUnitOutput): ?string
    {
        if (!preg_match('/Error:\s+Call to a member function expects\(\)\s+on null/i', $phpUnitOutput)) {
            return null;
        }

        return "INDICE DE CORRECTION ARCHITECTURE TEST (ERREUR EXPECTS ON NULL) :\n"
        . "Erreur fatale : Tu tentes d'appeler `expects()` sur une variable de mock qui vaut `null`.\n"
        . "Règle AAA et cycle de vie PHPUnit :\n"
        . "1. **Arrange** : Tu dois impérativement instancier ton mock "
            . "(ex: `\$this->repo = \$this->createMock(...)`) **avant** de configurer ses attentes (`\$this->repo->expects(...)`).\n"
        . "2. **Évite les fonctions d'aide personnalisées** : "
            . "Initialise tes mocks et ta SUT soit directement dans la méthode native `setUp()`, "
            . "soit au tout début de chaque méthode de test, jamais dans une méthode externe appelée après les `expects()`.";
    }

    /**
     * Intercepte les erreurs quand le LLM tente d'appeler des méthodes
     * de configuration de mock (expects, method) sur n'importe quel objet non mocké (SUT, DTO, service réel, etc.).
     */
    private function extractExpectsOnNonMockErrorHint(string $phpUnitOutput): ?string
    {
        if (!preg_match('/Call to undefined method\s+([^\:]+)::(expects|method)\(\)/i', $phpUnitOutput,$matches)) {
            return null;
        }

        $className =$matches[1];
        $methodCalled =$matches[2];

        return sprintf(
            "INDICE DE CORRECTION CRITIQUE (INTERDICTION DE CONFIGURER DES ATTENTES SUR UN OBJET RÉEL) :\n" .
            "Erreur fatale : Tu tentes d'appeler `%s()` sur la classe concrète ou non mockée `%s`.\n" .
            "Règle fondamentale PHPUnit : Les méthodes d'attente (`expects`, `method`) n'existent **que sur les mocks** (générés via `createMock()`).\n" .
            "Tu ne peux **jamais** configurer d'attentes sur un objet réel (que ce soit la SUT, un DTO, ou un service).\n\n" .
            "❌ **Code erroné à supprimer ou réécrire** :\n" .
            "```php\n" .
            "// Interdit sur un objet réel ou la SUT :\n" .
            "\$instance->expects(self::once())->method('...');\n" .
            "```\n\n" .
            "✔️ **Règle** :\n" .
            "- S'il s'agit de la SUT (`\$this->sut`), appelle directement sa vraie méthode sans `expects()`.\n" .
            "- S'il s'agit d'une dépendance externe, elle doit impérativement être déclarée et initialisée comme un mock dans ton `setUp()` pour accepter `expects()`.",
            $methodCalled,$className
        );
    }

    /**
     * Intercepte l'avertissement PHPUnit de test risqué lié au cumul de l'attente expectNotToPerformAssertions()
     * et de mocks configurés avec expects().
     */
    private function extractRiskyTestAssertionHint(string $phpUnitOutput): ?string
    {
        if (!preg_match('/This test is not expected to perform assertions but performed/i', $phpUnitOutput)) {
            return null;
        }

        return "INDICE DE CORRECTION TEST RISQUÉ (PHPUNIT) :\n"
        . "Alerte PHPUnit : Le test a été marqué comme risqué (`risky test`) car il utilise `expectNotToPerformAssertions()` 
        . tout en effectuant des assertions.\n"
        . "Règle PHPUnit : Les attentes sur les mocks (`expects()`, `method()`) comptent **automatiquement** comme des assertions.\n\n"
        . "**Correction à appliquer** :\n"
        . "Supprime immédiatement l'appel à `self::expectNotToPerformAssertions();` dans le ou les tests concernés. "
        . "Laisse les mocks valider les appels.";
    }

    /**
     * Intercepte les erreurs PHPUnit liées au nombre d'appels inattendu sur un mock
     * (ex : once() alors qu'il y a une boucle).
     */
    private function extractMockCountExpectationHint(string $phpUnitOutput): ?string
    {
        if (!preg_match('/was not expected to be called/i', $phpUnitOutput)) {
            return null;
        }

        return "INDICE DE CORRECTION PHPUNIT (ERREUR DE COMPTAGE D'APPELS DE MOCK) :\n"
        . "Erreur PHPUnit : Un mock a été configuré avec une attente stricte (ex: `expects(self::once())`), "
        . "mais la méthode a été appelée plusieurs fois lors de l'exécution (généralement à cause d'une boucle sur plusieurs éléments).\n\n"
        . "**Correction à appliquer** :\n"
        . "Modifie l'attente du mock concerné pour autoriser plusieurs appels. "
        . "Utilise `expects(self::any())` ou `expects(self::atLeastOnce())` au lieu de `once()` lorsque la méthode est appelée dans une boucle.";
    }
}
