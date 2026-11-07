<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Dto\GenerationResult;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Support\FixtureTestClass;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Support\GeneratorHarness;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Support\ScriptedLlmClient;
use PHPUnit\Framework\TestCase;

final class TestGeneratorV2RepairTest extends TestCase
{
    private ?GeneratorHarness $harness = null;

    /** @var list<string> */
    private array $progress = [];

    protected function tearDown(): void
    {
        $this->harness?->cleanup();
        $this->harness = null;
        $this->progress = [];
    }

    public function testLeSqueletteDeFixtureRespecteLaPorteDeQualite(): void
    {
        // ÉTANT DONNÉ le squelette de classe utilisé par tous les scénarios
        $h = $this->harness([]);
        $code = self::startCode();

        // QUAND la porte de qualité l'examine
        $report = $h->qualityGate->report($code, null);

        // ALORS elle ne signale rien : sinon un échec viendrait de la fixture, pas du scénario
        self::assertNull($report, (string) $report);
    }

    public function testUnTestRougeEstReparePuisLaClasseEstLivreeEntiere(): void
    {
        // ÉTANT DONNÉ un test rouge et un LLM qui renvoie une version verte de cette seule méthode
        $h = $this->harness([FixtureTestClass::method('testBeta')]);

        // QUAND on génère à partir de ce code
        $result = $this->generate($h, self::startCode());

        // ALORS la classe est livrée entière, sans test retiré ni rapport de revue
        self::assertSame(['testAlpha', 'testBeta', 'testGamma'], $h->patcher->names($result->code));
        self::assertStringNotContainsString('self::fail(', $result->code);
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);

        // ET le LLM a reçu une conversation neuve (système + utilisateur) sur ce seul test
        self::assertSame(1, $h->client->callCount());
        self::assertSame(['system', 'user'], array_column($h->client->conversations[0], 'role'));
        self::assertStringContainsString('testBeta', $h->client->conversations[0][1]['content']);

        // ET PHPUnit n'a tourné que deux fois : le code de départ, puis la méthode réparée
        self::assertCount(2, $h->runner->runs);
    }

    public function testUnTestIrreparableEstRetireApresTroisToursEtLeResteEstLivre(): void
    {
        // ÉTANT DONNÉ un LLM qui renvoie trois fois une version encore rouge du test
        $h = $this->harness([
            FixtureTestClass::method('testBeta', 'RED 2'),
            FixtureTestClass::method('testBeta', 'RED 3'),
            FixtureTestClass::method('testBeta', 'RED 4'),
        ]);

        // QUAND on génère en autorisant la livraison partielle
        $result = $this->generate($h, self::startCode());

        // ALORS le test est retiré avec ses trois tentatives, son dernier rapport et sa dernière version
        self::assertCount(1, $result->removed);
        self::assertSame('testBeta', $result->removed[0]['test']);
        self::assertSame(3, $result->removed[0]['attempts']);
        self::assertStringContainsString('RED 4', $result->removed[0]['report']);
        self::assertStringContainsString('RED 4', $result->removed[0]['code']);

        // ET le reste de la classe est livré
        self::assertSame(['testAlpha', 'testGamma'], $h->patcher->names($result->code));
        self::assertSame(3, $h->client->callCount());
        self::assertCount(5, $h->runner->runs); // départ + trois réparations + run après retrait

        // ET le rapport de revue est écrit et annoncé en progression
        $path = (string) $result->reviewPath;
        self::assertStringStartsWith($h->dir . '/failed_tests/review/', $path);
        self::assertFileExists($path);
        $review = (string) file_get_contents($path);
        self::assertStringContainsString('ne figurent PAS', $review);
        self::assertStringContainsString('## testBeta', $review);
        self::assertStringContainsString('Tentatives de réparation : 3', $review);
        self::assertStringContainsString('RED 4', $review);
        self::assertNotEmpty(array_filter($this->progress, static fn (string $m): bool => str_contains($m, 'Retrait de 1')));
    }

    public function testLeModeStrictNeRetireAucunTestEtLaBoucleHistoriqueEchoueNormalement(): void
    {
        // ÉTANT DONNÉ trois réponses par test, puis deux réécritures complètes toujours rouges
        $stillRed = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta', 'RED 5'),
            FixtureTestClass::method('testGamma'),
        );
        $h = $this->harness([
            FixtureTestClass::method('testBeta', 'RED 2'),
            FixtureTestClass::method('testBeta', 'RED 3'),
            FixtureTestClass::method('testBeta', 'RED 4'),
            $stillRed,
            $stillRed,
        ]);

        // QUAND on génère en mode strict
        try {
            $this->generate($h, self::startCode(), allowPartial: false);
            self::fail('Une TestCorrectionException était attendue.');
        } catch (TestCorrectionException $e) {
            // ALORS l'échec final est l'exception habituelle
            self::assertStringContainsString('Impossible de générer un test valide (PHPUnit)', $e->getMessage());
        }

        // ET la boucle historique a repris (3 tours par test + 2 réécritures), sans retrait ni rapport de revue
        self::assertSame(5, $h->client->callCount());
        self::assertDirectoryDoesNotExist($h->dir . '/failed_tests/review');
    }

    public function testUnTestDeLaBaselineNEstJamaisRetire(): void
    {
        // ÉTANT DONNÉ un test déjà présent dans le fichier existant (baseline), qui reste rouge après trois tours
        $green = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta'),
            FixtureTestClass::method('testGamma'),
        );
        $h = $this->harness([
            FixtureTestClass::method('testBeta', 'RED 2'),
            FixtureTestClass::method('testBeta', 'RED 3'),
            FixtureTestClass::method('testBeta', 'RED 4'),
            $green,
        ]);
        $baseline = FixtureTestClass::build(FixtureTestClass::method('testBeta'));

        // QUAND on génère en autorisant la livraison partielle
        $result = $this->generate($h, self::startCode(), baseline: $baseline);

        // ALORS rien n'est retiré : la boucle historique reprend et livre la classe réécrite
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);
        self::assertSame($green, $result->code);
        self::assertSame(['testAlpha', 'testBeta', 'testGamma'], $h->patcher->names($result->code));
        self::assertSame(4, $h->client->callCount());
    }

    public function testUneReponseRenommeeAbsenteOuInvalideConsommeLEssaiSansRelancerPhpunit(): void
    {
        // ÉTANT DONNÉ trois réponses inutilisables : méthode renommée, aucune méthode, syntaxe cassée
        $h = $this->harness([
            FixtureTestClass::method('testBetaRenamed'),
            "Voici ma correction : le test me semble correct, je n'y change rien.",
            "public function testBeta(): void\n{\n    self::assertSame(1,\n}",
        ]);

        // QUAND on génère en autorisant la livraison partielle
        $result = $this->generate($h, self::startCode());

        // ALORS les trois essais sont consommés et le test est retiré
        self::assertSame(3, $h->client->callCount());
        self::assertCount(1, $result->removed);
        self::assertSame('testBeta', $result->removed[0]['test']);
        self::assertSame(3, $result->removed[0]['attempts']);
        self::assertSame(['testAlpha', 'testGamma'], $h->patcher->names($result->code));

        // ET PHPUnit n'a tourné que deux fois : le départ et le run après retrait
        self::assertCount(2, $h->runner->runs);
    }

    public function testUneErreurFataleNEstPasAttribuableEtRenvoieALaBoucleHistorique(): void
    {
        // ÉTANT DONNÉ un code qui provoque une erreur fatale, et un LLM qui renvoie la classe complète corrigée
        $green = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta'),
            FixtureTestClass::method('testGamma'),
        );
        $h = $this->harness([$green]);
        $start = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::fatalMethod('testBeta'),
            FixtureTestClass::method('testGamma'),
        );

        // QUAND on génère à partir de ce code
        $result = $this->generate($h, $start);

        // ALORS aucune réparation par test n'a été tentée : le seul appel LLM est la réécriture complète
        self::assertSame(1, $h->client->callCount());
        self::assertSame($green, $result->code);
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);
        self::assertCount(2, $h->runner->runs);
    }

    public function testUnTestIgnorePourMethodePriveeNEstJamaisReparePuisLivreTelQuel(): void
    {
        // ÉTANT DONNÉ une classe avec un test rouge et un test ignoré pour méthode privée
        $start = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta', 'RED 1'),
            FixtureTestClass::skippedMethod('testSecret'),
        );
        $h = $this->harness([FixtureTestClass::method('testBeta')]);
        self::assertNull($h->qualityGate->report($start, null), 'La fixture doit passer la porte de qualité.');

        // QUAND on génère en autorisant la livraison partielle
        $result = $this->generate($h, $start);

        // ALORS le LLM n'est sollicité que pour le test rouge : un second appel lèverait « plus aucune réponse préparée »
        self::assertSame(1, $h->client->callCount());

        // ET le test ignoré reste dans la classe livrée, sans retrait ni rapport de revue
        self::assertSame(['testAlpha', 'testBeta', 'testSecret'], $h->patcher->names($result->code));
        self::assertStringContainsString('markTestSkipped', $result->code);
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);
    }

    public function testLesFixturesPhpstanRespectentLaPorteDeQualite(): void
    {
        // ÉTANT DONNÉ une classe avec une propriété et une méthode que le faux PHPStan rejette
        $h = $this->harness([]);
        $code = FixtureTestClass::build(
            FixtureTestClass::stanProperty('Erreur P'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur A'),
        );

        // QUAND la porte de qualité l'examine
        $report = $h->qualityGate->report($code, null);

        // ALORS elle ne signale rien : sinon l'échec viendrait de la fixture, pas du scénario
        self::assertNull($report, (string) $report);
    }

    public function testUneErreurPhpstanEstRepareeEnUnTourEtLaClasseEstLivreeEntiere(): void
    {
        // ÉTANT DONNÉ un test que PHPStan rejette et un LLM qui renvoie une version propre de cette seule méthode
        $h = $this->harness([FixtureTestClass::method('testBeta')]);

        // QUAND on génère à partir de ce code
        $result = $this->generate($h, self::stanStartCode());

        // ALORS la classe est livrée entière, corrigée, sans retrait ni rapport de revue
        self::assertSame(['testAlpha', 'testBeta', 'testGamma'], $h->patcher->names($result->code));
        self::assertStringNotContainsString('STAN[', $result->code);
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);

        // ET le LLM a reçu une conversation neuve avec le rapport PHPStan de ce seul test
        self::assertSame(1, $h->client->callCount());
        self::assertSame(['system', 'user'], array_column($h->client->conversations[0], 'role'));
        self::assertMatchesRegularExpression('/- Ligne \d+ : Erreur A/', $h->client->conversations[0][1]['content']);

        // ET le candidat est repassé par PHPStan puis par PHPUnit : 2 analyses, 2 runs
        self::assertCount(2, $h->stan->analyzed);
        self::assertCount(2, $h->runner->runs);
    }

    public function testUneErreurPhpstanIrreparableEstRetireeApresTroisToursEtLeResteEstLivre(): void
    {
        // ÉTANT DONNÉ un LLM qui renvoie trois fois une version que PHPStan rejette encore
        $h = $this->harness([
            FixtureTestClass::stanMethod('testBeta', 'Erreur B'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur C'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur D'),
        ]);

        // QUAND on génère en autorisant la livraison partielle
        $result = $this->generate($h, self::stanStartCode());

        // ALORS le test est retiré avec ses trois tentatives, son dernier rapport et sa dernière version
        self::assertCount(1, $result->removed);
        self::assertSame('testBeta', $result->removed[0]['test']);
        self::assertSame(3, $result->removed[0]['attempts']);
        self::assertStringContainsString('Erreur D', $result->removed[0]['report']);
        self::assertStringNotContainsString('Erreur C', $result->removed[0]['report']);
        self::assertStringContainsString('Erreur D', $result->removed[0]['code']);

        // ET le reste de la classe est livré
        self::assertSame(['testAlpha', 'testGamma'], $h->patcher->names($result->code));
        self::assertSame(3, $h->client->callCount());

        // ET PHPStan a analysé le départ, les trois candidats et la classe sans le test ; PHPUnit seulement le départ et cette classe
        self::assertCount(5, $h->stan->analyzed);
        self::assertCount(2, $h->runner->runs);

        // ET le rapport de revue est écrit
        $path = (string) $result->reviewPath;
        self::assertFileExists($path);
        $review = (string) file_get_contents($path);
        self::assertStringContainsString('## testBeta', $review);
        self::assertStringContainsString('Tentatives de réparation : 3', $review);
        self::assertStringContainsString('Erreur D', $review);
    }

    public function testLeModeStrictNeRetireAucunTestPhpstanEtLaBoucleHistoriqueEchoue(): void
    {
        // ÉTANT DONNÉ trois réponses par test toujours rejetées, puis une réécriture complète toujours rejetée
        $stillRejected = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur E'),
            FixtureTestClass::method('testGamma'),
        );
        $h = $this->harness([
            FixtureTestClass::stanMethod('testBeta', 'Erreur B'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur C'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur D'),
            $stillRejected,
        ]);

        // QUAND on génère en mode strict
        try {
            $this->generate($h, self::stanStartCode(), allowPartial: false);
            self::fail('Une TestCorrectionException était attendue.');
        } catch (TestCorrectionException $e) {
            // ALORS l'échec final est l'exception PHPStan habituelle
            self::assertStringContainsString('Échec PHPStan pour Foo', $e->getMessage());
        }

        // ET la boucle historique a repris (3 tours puis 1 réécriture complète), sans retrait ni rapport de revue
        self::assertSame(4, $h->client->callCount());
        self::assertDirectoryDoesNotExist($h->dir . '/failed_tests/review');
    }

    public function testUneErreurPhpstanHorsDUnTestRenvoieALaBoucleHistorique(): void
    {
        // ÉTANT DONNÉ une erreur PHPStan sur une propriété, rattachable à aucun test, et un LLM qui renvoie la classe corrigée
        $green = FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta'),
        );
        $h = $this->harness([$green]);
        $start = FixtureTestClass::build(
            FixtureTestClass::stanProperty('Erreur P'),
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta'),
        );

        // QUAND on génère à partir de ce code
        $result = $this->generate($h, $start);

        // ALORS aucune réparation par test n'a été tentée : le seul appel LLM est la réécriture complète
        self::assertSame(1, $h->client->callCount());
        self::assertSame($green, $result->code);
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);
    }

    public function testUneCorrectionPhpstanQuiCasseLExecutionEstReparee(): void
    {
        // ÉTANT DONNÉ une première correction qui satisfait PHPStan mais fait échouer PHPUnit, puis une correction valide
        $h = $this->harness([
            FixtureTestClass::method('testBeta', 'REGRESS'),
            FixtureTestClass::method('testBeta'),
        ]);

        // QUAND on génère à partir de ce code
        $result = $this->generate($h, self::stanStartCode());

        // ALORS la classe est livrée entière et corrigée, sans retrait
        self::assertSame(['testAlpha', 'testBeta', 'testGamma'], $h->patcher->names($result->code));
        self::assertStringNotContainsString('STAN[', $result->code);
        self::assertStringNotContainsString('self::fail(', $result->code);
        self::assertSame([], $result->removed);
        self::assertNull($result->reviewPath);

        // ET le second tour a reçu le rapport PHPUnit de la régression, pas celui de PHPStan
        self::assertSame(2, $h->client->callCount());
        self::assertStringContainsString('FooDynamicTest::testBeta', $h->client->conversations[1][1]['content']);

        // ET chaque candidat est passé par PHPStan puis PHPUnit : départ + deux candidats
        self::assertCount(3, $h->stan->analyzed);
        self::assertCount(3, $h->runner->runs);
    }

    /** @param list<string|callable> $responses */
    private function harness(array $responses): GeneratorHarness
    {
        return $this->harness = new GeneratorHarness($responses);
    }

    private function generate(
        GeneratorHarness $h,
        string $startCode,
        bool $allowPartial = true,
        ?string $baseline = null,
    ): GenerationResult {
        return $h->generator->generate(
            classCode: FixtureTestClass::SUT_CODE,
            filePath: 'src/Service/Foo.php',
            fqcn: 'App\\Service\\Foo',
            className: 'Foo',
            model: 'scripted-model',
            existingTestCode: $baseline,
            provider: ScriptedLlmClient::PROVIDER,
            startFromTestCode: $startCode,
            allowPartial: $allowPartial,
            onProgress: function (string $message): void {
                $this->progress[] = $message;
            },
        );
    }

    private static function startCode(): string
    {
        return FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::method('testBeta', 'RED 1'),
            FixtureTestClass::method('testGamma'),
        );
    }

    private static function stanStartCode(): string
    {
        return FixtureTestClass::build(
            FixtureTestClass::method('testAlpha'),
            FixtureTestClass::stanMethod('testBeta', 'Erreur A'),
            FixtureTestClass::method('testGamma'),
        );
    }
}
