<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Quality;

use Mika\TestGeneratorBundle\Quality\SoftAssertionRewriter;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Symfony\Component\Process\Process;

/**
 * Exécute le code assoupli avec le PHPUnit du bundle, ce qui vérifie la réécriture de bout en bout
 */
#[CoversClass(SoftAssertionRewriter::class)]
final class SoftAssertionRewriterTest extends TestCase
{
    private const SCENARIO = <<<'PHP'
<?php

declare(strict_types=1);

namespace Mika\Soft\Scenario;

use PHPUnit\Framework\TestCase;

final class MikaSoftScenarioTest extends TestCase
{
    public function testScenario(): void
    {
        // ÉTANT DONNÉ
        $result = ['total' => 1000.0, 'color' => '#ff4b5c', 'label' => 'PHASE 4'];

        // ALORS
        self::assertEqualsWithDelta(100.0, $result['total'], 0.0001);
        self::assertSame('#ff4b5c', $result['color']);
        self::assertSame('PHASE 2', $result['label']);
    }

    public function testSain(): void
    {
        self::assertTrue(true);
    }
}
PHP;

    #[Test]
    public function testToutesLesAssertionsFaussesSontReleveesEnUneExecution(): void
    {
        // ÉTANT DONNÉ un test dont deux assertions sur trois sont fausses
        $soft = $this->rewriter()->rewrite(self::SCENARIO, ['testScenario']);
        self::assertNotNull($soft);

        // QUAND on exécute réellement le code assoupli
        $output = $this->runPhpUnit($soft['code']);
        $found = SoftAssertionRewriter::parseOutput($output);

        // ALORS les deux échecs sont relevés, avec leur ligne d'origine, et seulement eux
        self::assertSame(['testScenario'], array_keys($found), $output);
        self::assertCount(2, $found['testScenario']);
        [$first, $second] = $found['testScenario'];
        self::assertSame(self::lineOf('assertEqualsWithDelta'), $first[0]);
        self::assertStringContainsString('matches expected 100.0', $first[1]);
        self::assertSame(self::lineOf("'PHASE 2'"), $second[0]);
        self::assertStringContainsString('two strings are identical', $second[1]);
        self::assertStringContainsString("-'PHASE 2'", $second[2]);
        self::assertStringContainsString("+'PHASE 4'", $second[2]);

        // ET le texte de chaque assertion est conservé, le test voisin n'est pas modifié
        self::assertSame("self::assertSame('PHASE 2', \$result['label']);", $soft['sources']['testScenario'][$second[0]]);
        self::assertSame(3, substr_count($soft['code'], 'catch ('));
        self::assertStringContainsString(
            "public function testSain(): void\n    {\n        self::assertTrue(true);",
            str_replace("\r\n", "\n", $soft['code']),
        );
    }

    #[Test]
    public function testUnTestSansAssertionDePremierNiveauNEstPasModifie(): void
    {
        // ÉTANT DONNÉ un nom de test inexistant
        // QUAND on réécrit
        $soft = $this->rewriter()->rewrite(self::SCENARIO, ['testInconnu']);

        // ALORS rien n'est produit
        self::assertNull($soft);
    }

    #[Test]
    public function testUnCodeIllisibleNeProduitRien(): void
    {
        // ÉTANT DONNÉ un code qui ne se parse pas
        // QUAND on réécrit
        $soft = $this->rewriter()->rewrite("<?php\nfunction {", ['testScenario']);

        // ALORS rien n'est produit
        self::assertNull($soft);
    }

    #[Test]
    public function testUnJeuDeDonneesNEstPasAttribueAuTest(): void
    {
        // ÉTANT DONNÉ un rapport dont l'en-tête désigne un jeu de données
        $output = "1) Foo\\FooTest::testX with data set #0 (1)\nMIKA_SOFT [[10,\"m\",\"\"]]\n";

        // QUAND on le lit
        $found = SoftAssertionRewriter::parseOutput($output);

        // ALORS il est ignoré : plusieurs jeux se mélangeraient sous le même nom
        self::assertSame([], $found);
    }

    #[Test]
    public function testLeTexteDeLIndiceNeRepeteLeDiffQueQuandIlApporteQuelqueChose(): void
    {
        // ÉTANT DONNÉ une assertion numérique et une assertion de chaînes
        $items = [
            ['line' => 105, 'source' => 'self::assertEqualsWithDelta(100.0, $r[\'a\'], 0.1);', 'message' => 'Failed asserting that 1000.0 matches expected 100.0.', 'diff' => "--- Expected\n+++ Actual\n@@ @@\n-100.0\n+1000.0"],
            ['line' => 108, 'source' => "self::assertSame('x', \$r['b']);", 'message' => 'Failed asserting that two strings are identical.', 'diff' => "--- Expected\n+++ Actual\n@@ @@\n-'x'\n+'y'"],
        ];

        // QUAND on fabrique la section.
        $md = SoftAssertionRewriter::render($items);

        // ALORS le diff n'apparaît que pour les chaînes
        self::assertStringContainsString('- ligne 105', $md);
        self::assertStringNotContainsString('-100.0', $md);
        self::assertStringContainsString("    -'x'", $md);
        self::assertStringContainsString('Corrige-les TOUTES', $md);
    }

    private static function lineOf(string $needle): int
    {
        return 1 + substr_count(substr(self::SCENARIO, 0, (int) strpos(self::SCENARIO, $needle)), "\n");
    }

    private function rewriter(): SoftAssertionRewriter
    {
        return new SoftAssertionRewriter((new ParserFactory())->createForNewestSupportedVersion());
    }

    /**
     * @throws RandomException
     */
    private function runPhpUnit(string $code): string
    {
        $dir = sys_get_temp_dir() . '/mika_soft_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/MikaSoftScenarioTest.php';
        file_put_contents($file, $code);

        try {
            $root = \dirname(__DIR__, 2);
            $process = new Process(
                [\PHP_BINARY, $root . '/vendor/bin/phpunit', '--no-configuration', '--bootstrap', $root . '/vendor/autoload.php', '--colors=never', $file],
                null,
                ['XDEBUG_MODE' => 'off'],
            );
            $process->run();

            return $process->getOutput();
        } finally {
            if (is_file($file)) {
                unlink($file);
                rmdir($dir);
            }
        }
    }
}
