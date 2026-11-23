<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Enum\ExpectationType;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Exception\ScenarioParseException;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionalScenarioParser::class)]
final class FunctionalScenarioParserTest extends TestCase
{
    private const REFERENCE = <<<'MD'
# Créer une position en attente

## Contexte
- Authentification : mot de passe, profil « trader »
- Page de départ : /

## Étapes
1. Cliquer sur le bouton « Nouvelle position en attente »
2. Saisir « 7200.00 » dans « Prix Achat CAC »
3. Saisir « 25 » dans « Quantité »
4. Cliquer sur le bouton « Enregistrer la position »

## Attentes
- Le panneau « Nouvelle Position en attente » est fermé
- La table position contient 1 ligne de plus
MD;

    private FunctionalScenarioParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FunctionalScenarioParser();
    }

    #[Test]
    public function testParsesTheReferenceScenario(): void
    {
        // ÉTANT DONNÉ le scénario de référence de la création d'une position

        // QUAND on le parse
        $scenario = $this->parser->parse(self::REFERENCE);

        // ALORS le titre, le contexte, les étapes et les attentes sont lus
        self::assertSame('Créer une position en attente', $scenario->title);
        self::assertSame('password', $scenario->context->authStrategy);
        self::assertSame('trader', $scenario->context->authProfile);
        self::assertSame('/', $scenario->context->startPath);
        self::assertCount(4, $scenario->steps);
        self::assertCount(2, $scenario->expectations);
        self::assertSame(StepAction::Click, $scenario->steps[0]->action);
        self::assertSame('Nouvelle position en attente', $scenario->steps[0]->target);
        self::assertSame('bouton', $scenario->steps[0]->noun);
        self::assertSame(StepAction::Fill, $scenario->steps[1]->action);
        self::assertSame('Prix Achat CAC', $scenario->steps[1]->target);
        self::assertSame('7200.00', $scenario->steps[1]->value);
        self::assertSame(ExpectationType::PanelClosed, $scenario->expectations[0]->type);
        self::assertSame(ExpectationType::RowsDelta, $scenario->expectations[1]->type);
        self::assertSame('position', $scenario->expectations[1]->subject);
        self::assertSame(1, $scenario->expectations[1]->count);
        self::assertSame(['position'], $scenario->tablesCounted());
    }

    #[Test]
    public function testIndexesAndLinesAreOneBased(): void
    {
        // ÉTANT DONNÉ le scénario de référence
        // QUAND on le parse
        $scenario = $this->parser->parse(self::REFERENCE);

        // ALORS les index suivent l'ordre du fichier et les lignes pointent le Markdown
        self::assertSame(1, $scenario->steps[0]->index);
        self::assertSame(4, $scenario->steps[3]->index);
        self::assertSame(1, $scenario->expectations[0]->index);
        self::assertSame(8, $scenario->steps[0]->line);
    }

    /**
     * @return iterable<string, array{string, StepAction, ?string, ?string, ?string}>
     */
    public static function stepProvider(): iterable
    {
        yield 'visite entre guillemets' => ['Aller sur « /positions »', StepAction::Visit, '/positions', null, null];
        yield 'visite sans guillemets, casse conservée' => ['Aller sur /Portfolio', StepAction::Visit, '/Portfolio', null, null];
        yield 'ouvrir une page' => ['Ouvrir la page /settings', StepAction::Visit, '/settings', null, null];
        yield 'clic simple' => ['Cliquer sur « Valider »', StepAction::Click, 'Valider', null, null];
        yield 'clic sur une icône' => ["Cliquer sur l'icône « Menu »", StepAction::Click, 'Menu', null, 'icone'];
        yield 'clic sur un lien' => ['Cliquer sur le lien « Dashboard »', StepAction::Click, 'Dashboard', null, 'lien'];
        yield 'saisie' => ['Saisir « AAPL » dans le champ « Symbole »', StepAction::Fill, 'Symbole', 'AAPL', null];
        yield 'saisie vide' => ['Saisir «» dans « Quantité »', StepAction::Fill, 'Quantité', '', null];
        yield 'remplir avec' => ['Remplir le champ « Symbole » avec « AAPL »', StepAction::Fill, 'Symbole', 'AAPL', null];
        yield 'guillemets droits' => ['Saisir "x" dans "y"', StepAction::Fill, 'y', 'x', null];
        yield 'sélection' => ['Sélectionner « Achat » dans la liste « Sens »', StepAction::Select, 'Sens', 'Achat', null];
        yield 'case à cocher' => ['Cocher la case « Actif »', StepAction::Check, 'Actif', null, null];
        yield 'décocher' => ['Décocher « Actif »', StepAction::Uncheck, 'Actif', null, null];
    }

    #[Test]
    #[DataProvider('stepProvider')]
    public function testParsesEachStepForm(string $line, StepAction $action, ?string $target, ?string $value, ?string $noun): void
    {
        // ÉTANT DONNÉ un scénario dont la première étape est la ligne testée
        $markdown = "# T\n## Étapes\n1. {$line}\n## Attentes\n- L'URL est /x\n";

        // QUAND on le parse
        $step = $this->parser->parse($markdown)->steps[0];

        // ALORS l'action, la cible, la valeur et le nom du contrôle sont reconnus
        self::assertSame($action, $step->action);
        self::assertSame($target, $step->target);
        self::assertSame($value, $step->value);
        self::assertSame($noun, $step->noun);
    }

    /**
     * @return iterable<string, array{string, ExpectationType, ?string, ?int}>
     */
    public static function expectationProvider(): iterable
    {
        yield 'url exacte' => ["L'URL est /positions", ExpectationType::UrlIs, '/positions', null];
        yield 'url entre guillemets' => ["L'URL est « /positions »", ExpectationType::UrlIs, '/positions', null];
        yield 'url contient' => ["L'URL contient « /settings »", ExpectationType::UrlContains, '/settings', null];
        yield 'url contient sans guillemets' => ["L'URL contient settings", ExpectationType::UrlContains, 'settings', null];
        yield 'message affiché' => ['Le message « Position créée » est affiché', ExpectationType::TextVisible, 'Position créée', null];
        yield 'texte absent' => ["« Erreur » n'est plus affiché", ExpectationType::TextAbsent, 'Erreur', null];
        yield 'texte non affiché' => ["Le texte « Oups » n'est pas affiché", ExpectationType::TextAbsent, 'Oups', null];
        yield 'panneau ouvert' => ['Le panneau « Nouvelle Position en attente » est ouvert', ExpectationType::PanelOpen, 'Nouvelle Position en attente', null];
        yield 'panneau fermé' => ['Le panneau « X » est fermé', ExpectationType::PanelClosed, 'X', null];
        yield 'panneau masqué' => ['Le panneau « X » est masqué', ExpectationType::PanelClosed, 'X', null];
        yield 'une ligne de plus' => ['La table position contient 1 ligne de plus', ExpectationType::RowsDelta, 'position', 1];
        yield 'trois lignes de plus' => ['La table « Position » contient 3 lignes de plus', ExpectationType::RowsDelta, 'Position', 3];
        yield 'deux lignes de moins' => ['La table position contient 2 lignes de moins', ExpectationType::RowsDelta, 'position', -2];
        yield 'aucune ligne' => ['La table position ne contient aucune ligne', ExpectationType::RowsTotal, 'position', 0];
        yield 'total de lignes' => ['La table position contient 5 lignes', ExpectationType::RowsTotal, 'position', 5];
    }

    #[Test]
    #[DataProvider('expectationProvider')]
    public function testParsesEachExpectationForm(string $line, ExpectationType $type, ?string $subject, ?int $count): void
    {
        // ÉTANT DONNÉ un scénario dont la première attente est la ligne testée
        $markdown = "# T\n## Étapes\n1. Aller sur /x\n## Attentes\n- {$line}\n";

        // QUAND on le parse
        $expectation = $this->parser->parse($markdown)->expectations[0];

        // ALORS le type, le sujet et le nombre sont reconnus
        self::assertSame($type, $expectation->type);
        self::assertSame($subject, $expectation->subject);
        self::assertSame($count, $expectation->count);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'verbe inconnu' => ["# T\n## Étapes\n1. Dancer sur « x »\n## Attentes\n- L'URL est /x\n", 'étape non reconnue'];
        yield 'libellé sans guillemets' => ["# T\n## Étapes\n1. Cliquer Valider\n## Attentes\n- L'URL est /x\n", 'étape non reconnue'];
        yield 'attente inconnue' => ["# T\n## Étapes\n1. Aller sur /x\n## Attentes\n- Tout va bien\n", 'attente non reconnue'];
        yield 'nombre de lignes manquant' => ["# T\n## Étapes\n1. Aller sur /x\n## Attentes\n- La table position contient ligne\n", 'nombre de lignes manquant'];
        yield 'sans attente' => ["# T\n## Étapes\n1. Aller sur /x\n", 'aucune attente'];
        yield 'sans étape' => ["# T\n## Attentes\n- L'URL est /x\n", 'aucune étape'];
        yield 'section inconnue' => ["# T\n## Divers\n- a\n", 'section inconnue'];
        yield 'texte libre dans une section' => ["# T\n## Étapes\ntexte libre\n", 'élément de liste attendu'];
        yield 'clé de contexte inconnue' => ["# T\n## Contexte\n- Foo : bar\n## Étapes\n1. Aller sur /x\n## Attentes\n- L'URL est /x\n", 'clé de contexte inconnue'];
        yield 'page de départ relative' => ["# T\n## Contexte\n- Page de départ : positions\n## Étapes\n1. Aller sur /x\n## Attentes\n- L'URL est /x\n", 'commençant par « / »'];
        yield 'authentification inconnue' => ["# T\n## Contexte\n- Authentification : magie\n## Étapes\n1. Aller sur /x\n## Attentes\n- L'URL est /x\n", 'authentification inconnue'];
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidScenarios(string $markdown, string $messagePart): void
    {
        // ÉTANT DONNÉ un scénario invalide
        // QUAND on le parse
        // ALORS une exception explicite mentionne le problème
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        $this->parser->parse($markdown);
    }

    #[Test]
    public function testParseErrorsCarryTheLineNumber(): void
    {
        // ÉTANT DONNÉ une étape invalide en ligne 3
        $markdown = "# T\n## Étapes\n1. Dancer sur « x »\n## Attentes\n- L'URL est /x\n";

        // QUAND on le parse
        try {
            $this->parser->parse($markdown);
            self::fail('Une exception était attendue.');
        } catch (ScenarioParseException $e) {
            // ALORS le message indique la ligne fautive
            self::assertStringContainsString('Ligne 3', $e->getMessage());
        }
    }

    #[Test]
    public function testWebssoProfileFromAnotherLine(): void
    {
        // ÉTANT DONNÉ un contexte WEBSSO avec un profil sur une ligne séparée
        $markdown = "# T\n## Contexte\n- Authentification : websso\n- Profil : « admin »\n## Étapes\n1. Aller sur /x\n## Attentes\n- L'URL est /x\n";

        // QUAND on le parse
        $context = $this->parser->parse($markdown)->context;

        // ALORS la stratégie et le profil sont lus
        self::assertSame('websso', $context->authStrategy);
        self::assertSame('admin', $context->authProfile);
    }

    #[Test]
    public function testAnonymousScenarioHasNoStrategy(): void
    {
        // ÉTANT DONNÉ un scénario sans authentification
        $markdown = "# T\n## Contexte\n- Authentification : aucune\n## Étapes\n1. Aller sur /x\n## Attentes\n- L'URL est /x\n";

        // QUAND on le parse
        $context = $this->parser->parse($markdown)->context;

        // ALORS aucune stratégie n'est retenue
        self::assertNull($context->authStrategy);
        self::assertNull($context->authProfile);
    }

    #[Test]
    public function testToleratesWindowsLineEndingsAndBom(): void
    {
        // ÉTANT DONNÉ un fichier enregistré avec BOM et fins de ligne CRLF
        $markdown = "\u{FEFF}# T\r\n## Étapes\r\n1. Aller sur /x\r\n## Attentes\r\n- L'URL est /x\r\n";

        // QUAND on le parse
        $scenario = $this->parser->parse($markdown);

        // ALORS le titre et les listes sont lus normalement
        self::assertSame('T', $scenario->title);
        self::assertCount(1, $scenario->steps);
    }

    #[Test]
    public function testTitleFallsBackToFileName(): void
    {
        // ÉTANT DONNÉ un scénario sans titre
        $markdown = "## Étapes\n1. Aller sur /x\n## Attentes\n- L'URL est /x\n";

        // QUAND on le parse avec un chemin source
        $scenario = $this->parser->parse($markdown, '/tmp/creation-position.md');

        // ALORS le titre est le nom du fichier
        self::assertSame('creation-position', $scenario->title);
    }

    #[Test]
    public function testFullUrlsInExpectationsAreReducedToPathAndQuery(): void
    {
        // ÉTANT DONNÉ un scénario dont les attentes citent des URL complètes
        $markdown = <<<'MD'
# Navigation

## Étapes
1. Cliquer sur le lien « Positions »

## Attentes
- L'URL est http://localhost:8000/positions?page=2
- L'URL contient /positions
MD;

        // QUAND on l'analyse
        $scenario = (new FunctionalScenarioParser())->parse($markdown);

        // ALORS seuls le chemin et la requête sont conservés
        self::assertSame('/positions?page=2', $scenario->expectations[0]->subject);
        self::assertSame('/positions', $scenario->expectations[1]->subject);
    }
}
