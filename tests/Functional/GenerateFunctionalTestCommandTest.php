<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Command\GenerateFunctionalTestCommand;
use Mika\TestGeneratorBundle\Functional\Dto\TestRunResult;
use Mika\TestGeneratorBundle\Functional\Exception\VerificationException;
use Mika\TestGeneratorBundle\Functional\Generation\FunctionalTestGenerator;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ExecutorProbeRunner;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeGeneratedTestRunner;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeStepChooser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(GenerateFunctionalTestCommand::class)]
final class GenerateFunctionalTestCommandTest extends TestCase
{
    private const AMBIGUOUS = "# Modifier l'objectif\n\n## Étapes\n1. Cliquer sur « Modifier l'objectif Core »\n\n## Attentes\n- L'URL est /\n";

    private string $project;
    private FakeStepChooser $chooser;
    private FakeGeneratedTestRunner $runner;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/mika_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->project, 0777, true);
        $this->chooser = new FakeStepChooser(0);
        $this->runner = new FakeGeneratedTestRunner();
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    #[Test]
    public function testWritesTheGeneratedTestUnderTheNamespaceFolder(): void
    {
        // ÉTANT DONNÉ le scénario de création
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--no-run' => true]);

        // ALORS le test est écrit dans tests/Dynamic/Functional et le compte rendu est affiché
        $file = $this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteTest.php';
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertFileExists($file);
        self::assertStringContainsString('final class CreerUnePositionEnAttenteTest extends E2ETest', (string) file_get_contents($file));
        self::assertStringContainsString('Test écrit : ' . $file, $tester->getDisplay());
        self::assertStringContainsString('[règle]', $tester->getDisplay());
    }

    #[Test]
    public function testRefusesToOverwriteWithoutForce(): void
    {
        // ÉTANT DONNÉ un test déjà généré
        $tester = $this->tester();
        $scenario = $this->scenario($this->creation());
        $tester->execute(['scenario' => $scenario, '--no-run' => true]);
        $file = $this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteTest.php';
        file_put_contents($file, 'modifié à la main');

        // QUAND on relance sans --force
        $status = $tester->execute(['scenario' => $scenario, '--no-run' => true]);

        // ALORS le fichier est conservé et l'erreur propose --force
        self::assertSame(1, $status);
        self::assertSame('modifié à la main', file_get_contents($file));
        self::assertStringContainsString('Le fichier existe déjà', $tester->getDisplay());

        // QUAND on relance avec --force
        $status = $tester->execute(['scenario' => $scenario, '--no-run' => true, '--force' => true]);

        // ALORS il est remplacé
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringContainsString('final class', (string) file_get_contents($file));
    }

    #[Test]
    public function testDryRunPrintsTheSourceAndWritesNothing(): void
    {
        // ÉTANT DONNÉ le scénario de création
        $tester = $this->tester();

        // QUAND on lance la commande en --dry-run
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--dry-run' => true]);

        // ALORS le code est affiché et aucun dossier de test n'est créé
        self::assertSame(0, $status);
        self::assertStringContainsString('<?php', $tester->getDisplay());
        self::assertStringContainsString('$this->loginAs(\'trader\', \'password\');', $tester->getDisplay());
        self::assertFalse(is_dir($this->project . '/tests'));
    }

    #[Test]
    public function testNoLlmStopsOnAnAmbiguousStepAndListsTheCandidates(): void
    {
        // ÉTANT DONNÉ une étape ambiguë
        $tester = $this->tester();

        // QUAND on lance la commande avec --no-llm
        $status = $tester->execute(['scenario' => $this->scenario(self::AMBIGUOUS), '--no-llm' => true]);

        // ALORS le code de sortie est 2, les candidats sont listés, le choix n'est pas sollicité et rien n'est écrit
        self::assertSame(GenerateFunctionalTestCommand::EXIT_NEEDS_DECISION, $status);
        self::assertStringContainsString('Décision requise à l\'étape 1', $tester->getDisplay());
        self::assertStringContainsString('Candidats :', $tester->getDisplay());
        self::assertStringContainsString('Aucun test généré', $tester->getDisplay());
        self::assertSame(0, $this->chooser->calls);
        self::assertSame([], glob($this->project . '/tests/*') ?: []);
    }

    #[Test]
    public function testLetsTheChooserResolveAnAmbiguousStep(): void
    {
        // ÉTANT DONNÉ une étape ambiguë et un choix actif
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario(self::AMBIGUOUS), '--no-run' => true]);

        // ALORS le test est écrit, l'étape est rapportée « LLM » et le sélecteur fragile est signalé
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertSame(1, $this->chooser->calls);
        self::assertStringContainsString('[LLM] (fragile)', $tester->getDisplay());
        self::assertStringContainsString('Avertissement : Étape 1 : sélecteur fragile', $tester->getDisplay());
        self::assertFileExists($this->project . '/tests/Dynamic/Functional/ModifierLObjectifTest.php');
    }

    #[Test]
    public function testShowsTheCandidatesAndThePageWhenTheChooserFails(): void
    {
        // ÉTANT DONNÉ un choix qui échoue (LLM injoignable, par exemple) sur une étape ambiguë
        $this->chooser = new FakeStepChooser(0, new \RuntimeException('Response does not contain message.'));
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario(self::AMBIGUOUS)]);

        // ALORS l'échec est rapporté avec la page courante et les candidats, pour corriger le scénario sans LLM
        self::assertSame(1, $status);
        self::assertStringContainsString('Échec : Étape 1 : choix impossible : Response does not contain message.', $tester->getDisplay());
        self::assertStringContainsString('Page courante : /', $tester->getDisplay());
        self::assertStringContainsString('Candidats :', $tester->getDisplay());
    }

    #[Test]
    public function testReturnsFailureWhenTheScenarioFails(): void
    {
        // ÉTANT DONNÉ un scénario dont l'attente est fausse
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario(str_replace("L'URL est /", "L'URL est /introuvable", self::AMBIGUOUS))]);

        // ALORS le code de sortie est 1 avec l'échec, et rien n'est écrit
        self::assertSame(1, $status);
        self::assertStringContainsString('Échec : Attente 1', $tester->getDisplay());
        self::assertStringContainsString('Aucun test généré.', $tester->getDisplay());
        self::assertSame([], glob($this->project . '/tests/*') ?: []);
    }

    #[Test]
    public function testHonoursOutputClassAndNamespaceOptions(): void
    {
        // ÉTANT DONNÉ des options de classe, de namespace et de sortie
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute([
            'scenario' => $this->scenario($this->creation()),
            '--class' => 'MaCreationTest',
            '--namespace' => 'Acme\Tests\E2E',
            '--base-class' => 'Acme\Tests\BaseTest',
            '--output' => 'var/generated',
        ]);

        // ALORS le fichier porte le nom, le namespace et la classe de base demandés
        $file = $this->project . '/var/generated/MaCreationTest.php';
        self::assertSame(0, $status, $tester->getDisplay());
        $source = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Acme\Tests\E2E;', $source);
        self::assertStringContainsString('use Acme\Tests\BaseTest;', $source);
        self::assertStringContainsString('final class MaCreationTest extends BaseTest', $source);
    }

    #[Test]
    public function testRejectsAForeignNamespaceWithoutOutput(): void
    {
        // ÉTANT DONNÉ un namespace hors de App\Tests et aucune sortie
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--namespace' => 'Acme\Tests']);

        // ALORS l'erreur demande --output
        self::assertSame(1, $status);
        self::assertStringContainsString('Utilisez --output', $tester->getDisplay());
    }

    #[Test]
    public function testHonoursTheClassSuffixOption(): void
    {
        // ÉTANT DONNÉ un suffixe de classe personnalisé
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--class-suffix' => 'Scenario', '--no-run' => true]);

        // ALORS le fichier et la classe portent ce suffixe
        $file = $this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteScenario.php';
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringContainsString('final class CreerUnePositionEnAttenteScenario extends', (string) file_get_contents($file));
    }

    #[Test]
    public function testRejectsAnInvalidClassSuffix(): void
    {
        // ÉTANT DONNÉ un suffixe qui ne serait pas un identifiant PHP
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--class-suffix' => 'Test; evil()']);

        // ALORS elle est refusée avant toute génération
        self::assertSame(1, $status);
        self::assertStringContainsString('--class-suffix', $tester->getDisplay());
        self::assertSame([], $this->runner->files);
    }

    #[Test]
    public function testRejectsAnInvalidMaxDecisions(): void
    {
        // ÉTANT DONNÉ une valeur non numérique
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--max-decisions' => 'beaucoup']);

        // ALORS elle est refusée avant toute génération
        self::assertSame(1, $status);
        self::assertStringContainsString('--max-decisions', $tester->getDisplay());
    }

    #[Test]
    public function testReportsAMissingScenarioFile(): void
    {
        // ÉTANT DONNÉ un scénario inexistant
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => '/nonexistent/scenario.md']);

        // ALORS l'échec est rapporté
        self::assertSame(1, $status);
        self::assertStringContainsString('Échec :', $tester->getDisplay());
    }

    #[Test]
    public function testMovesAGreenTestToTheGreenFolderAndAlignsItsNamespace(): void
    {
        // ÉTANT DONNÉ un test généré que PHPUnit juge vert
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation())]);

        // ALORS PHPUnit a reçu le fichier de la zone d'attente, puis le test a été déplacé dans tests/Functional
        $waiting = $this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteTest.php';
        $green = $this->project . '/tests/Functional/CreerUnePositionEnAttenteTest.php';
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertSame([$waiting], $this->runner->files);
        self::assertFileDoesNotExist($waiting);
        self::assertFileExists($green);
        $source = (string) file_get_contents($green);
        self::assertStringContainsString('namespace App\Tests\Functional;', $source);
        self::assertStringContainsString('use App\Tests\E2E\E2ETest;', $source);
        self::assertStringContainsString('PHPUnit : vert. Test déplacé : ' . $green, $tester->getDisplay());
    }

    #[Test]
    public function testMovesAFailingTestToTheFailedFolderWithItsLog(): void
    {
        // ÉTANT DONNÉ un test généré que PHPUnit juge rouge
        $this->runner = new FakeGeneratedTestRunner(new TestRunResult(false, 1, "FAILURES!\nTests: 1, Assertions: 1, Failures: 1.\n"));
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation())]);

        // ALORS le test est mis de côté avec son journal, la zone d'attente est vide, rien n'est livré et le code est 1
        $failed = $this->project . '/var/failed_tests/functional/CreerUnePositionEnAttenteTest.php';
        self::assertSame(1, $status);
        self::assertFileExists($failed);
        self::assertStringContainsString('namespace App\Tests\Dynamic\Functional;', (string) file_get_contents($failed));
        self::assertStringContainsString('Failures: 1', (string) file_get_contents(substr($failed, 0, -4) . '.log'));
        self::assertFileDoesNotExist($this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteTest.php');
        self::assertFileDoesNotExist($this->project . '/tests/Functional/CreerUnePositionEnAttenteTest.php');
        self::assertStringContainsString('PHPUnit : rouge', $tester->getDisplay());
        self::assertStringContainsString('Failures: 1', $tester->getDisplay());
        self::assertStringContainsString('Test mis de côté : ' . $failed, $tester->getDisplay());
    }

    #[Test]
    public function testKeepsTheTestWhereOutputPutsItWhateverTheResult(): void
    {
        // ÉTANT DONNÉ --output et un test rouge
        $this->runner = new FakeGeneratedTestRunner(new TestRunResult(false, 1, "FAILURES!\n"));
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--output' => 'tests/Mine']);

        // ALORS le test reste là où l'utilisateur l'a demandé, sans déplacement, mais le code de sortie reflète l'échec
        self::assertSame(1, $status);
        self::assertFileExists($this->project . '/tests/Mine/CreerUnePositionEnAttenteTest.php');
        self::assertFalse(is_dir($this->project . '/var/failed_tests'));
        self::assertStringContainsString('Test conservé', $tester->getDisplay());
    }

    #[Test]
    public function testLeavesTheTestInTheWaitingFolderWhenPhpunitCannotBeLaunched(): void
    {
        // ÉTANT DONNÉ un PHPUnit introuvable
        $this->runner = new FakeGeneratedTestRunner(new VerificationException('PHPUnit introuvable : /x/vendor/bin/phpunit'));
        $tester = $this->tester();

        // QUAND on lance la commande
        $status = $tester->execute(['scenario' => $this->scenario($this->creation())]);

        // ALORS le test n'est pas déplacé : il n'est ni validé ni en échec
        self::assertSame(1, $status);
        self::assertFileExists($this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteTest.php');
        self::assertStringContainsString('PHPUnit introuvable', $tester->getDisplay());
        self::assertStringContainsString('n\'a pas pu être vérifié', $tester->getDisplay());
    }

    #[Test]
    public function testRefusesBeforeRunningWhenTheGreenDestinationAlreadyExists(): void
    {
        // ÉTANT DONNÉ un test déjà présent dans le dossier des tests verts
        mkdir($this->project . '/tests/Functional', 0777, true);
        $green = $this->project . '/tests/Functional/CreerUnePositionEnAttenteTest.php';
        file_put_contents($green, 'à la main');
        $tester = $this->tester();

        // QUAND on lance la commande sans --force
        $status = $tester->execute(['scenario' => $this->scenario($this->creation())]);

        // ALORS elle s'arrête avant d'écrire ou d'exécuter quoi que ce soit
        self::assertSame(1, $status);
        self::assertSame('à la main', file_get_contents($green));
        self::assertSame([], $this->runner->files);
        self::assertFileDoesNotExist($this->project . '/tests/Dynamic/Functional/CreerUnePositionEnAttenteTest.php');
        self::assertStringContainsString($green, $tester->getDisplay());

        // QUAND on relance avec --force
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--force' => true]);

        // ALORS le test vert remplace l'ancien
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringContainsString('final class', (string) file_get_contents($green));
    }

    #[Test]
    public function testDoesNotRunPhpunitWithNoRunNorWithDryRun(): void
    {
        // ÉTANT DONNÉ --no-run, puis --dry-run
        $tester = $this->tester();

        // QUAND on lance la commande
        $tester->execute(['scenario' => $this->scenario($this->creation()), '--no-run' => true]);
        $tester->execute(['scenario' => $this->scenario($this->creation()), '--dry-run' => true]);

        // ALORS PHPUnit n'est jamais lancé
        self::assertSame([], $this->runner->files);
    }

    #[Test]
    public function testHonoursTheGreenAndFailedFolderOptions(): void
    {
        // ÉTANT DONNÉ un dossier vert sous tests/ et un dossier d'échec personnalisé
        $tester = $this->tester();

        // QUAND on lance la commande avec un test vert
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--green-dir' => 'tests/Functional/Browser', '--failed-dir' => 'var/ko']);

        // ALORS le namespace suit le dossier
        $green = $this->project . '/tests/Functional/Browser/CreerUnePositionEnAttenteTest.php';
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringContainsString('namespace App\Tests\Functional\Browser;', (string) file_get_contents($green));

        // QUAND le dossier vert est hors de tests/ (et le test rouge)
        $this->runner = new FakeGeneratedTestRunner(new TestRunResult(false, 1, "FAILURES!\n"));
        $tester = $this->tester();
        $status = $tester->execute(['scenario' => $this->scenario($this->creation()), '--failed-dir' => 'var/ko']);

        // ALORS le test rouge est dans le dossier demandé
        self::assertSame(1, $status);
        self::assertFileExists($this->project . '/var/ko/CreerUnePositionEnAttenteTest.php');
        self::assertFileExists($this->project . '/var/ko/CreerUnePositionEnAttenteTest.log');
    }

    private function tester(): CommandTester
    {
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), $this->chooser);

        return new CommandTester(new GenerateFunctionalTestCommand($generator, $this->runner, $this->project));
    }

    private function creation(): string
    {
        return (string) (new \ReflectionClassConstant(ScenarioExecutorTest::class, 'CREATION'))->getValue();
    }

    private function scenario(string $markdown): string
    {
        $path = $this->project . '/scenario-' . bin2hex(random_bytes(3)) . '.md';
        file_put_contents($path, $markdown);

        return $path;
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        foreach (glob($path . '/*') ?: [] as $child) {
            $this->remove($child);
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
