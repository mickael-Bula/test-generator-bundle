<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use Mika\TestGeneratorBundle\Functional\Probe\PhpUnitProbeRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpUnitProbeRunner::class)]
final class PhpUnitProbeRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        putenv('MIKA_PROBE_DECISIONS');

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Test]
    public function testReadsTheResultWrittenByTheProbeProcess(): void
    {
        // ÉTANT DONNÉ une sonde (processus PHP) qui écrit un résultat « ok » avec un avertissement
        $runner = $this->runner('ok');

        // QUAND on la lance
        $result = $runner->run($this->scenarioFile(), new ScenarioDecisions());

        // ALORS le résultat est relu depuis le fichier de sortie
        self::assertSame(ProbeStatus::Ok, $result->status);
        self::assertSame(['avertissement de la sonde'], $result->warnings);
    }

    #[Test]
    public function testPassesTheScenarioAndTheDecisionsToTheProcess(): void
    {
        // ÉTANT DONNÉ une décision pour l'étape 2
        $scenario = $this->scenarioFile();
        $decisions = (new ScenarioDecisions())->with(2, new ScannedElement(tag: 'button', selector: '#choisi'));

        // QUAND on lance la sonde
        $result = $this->runner('echo')->run($scenario, $decisions);

        // ALORS le processus a reçu le chemin réel du scénario et le JSON des décisions
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('scenario=' . realpath($scenario), (string) $result->error);
        self::assertStringContainsString('#choisi', (string) $result->error);
    }

    #[Test]
    public function testDoesNotLeakStaleDecisionsFromTheParentEnvironment(): void
    {
        // ÉTANT DONNÉ une variable MIKA_PROBE_DECISIONS restée dans l'environnement du processus courant
        putenv('MIKA_PROBE_DECISIONS=/chemin/perime.json');

        // QUAND on lance la sonde sans décision
        $result = $this->runner('echo')->run($this->scenarioFile(), new ScenarioDecisions());

        // ALORS le processus ne la voit pas
        self::assertStringContainsString('decisions=none', (string) $result->error);
    }

    #[Test]
    public function testAppliesTheConfiguredEnvironmentAndWorkingDirectory(): void
    {
        // ÉTANT DONNÉ un environnement APP_ENV=test et XDEBUG_MODE=off
        $result = $this->runner('env', ['APP_ENV' => 'test', 'XDEBUG_MODE' => 'off'])->run($this->scenarioFile(), new ScenarioDecisions());

        // ALORS le processus les reçoit et s'exécute dans le dossier du projet
        self::assertStringContainsString('app_env=test;xdebug=off', (string) $result->error);
        self::assertStringContainsString('cwd=' . realpath(__DIR__ . '/Fixture'), (string) $result->error);
    }

    #[Test]
    public function testReportsTheProcessOutputWhenNoResultIsWritten(): void
    {
        // ÉTANT DONNÉ une sonde qui plante avant d'écrire (code 3) en affichant des messages
        // QUAND on la lance
        // ALORS l'erreur donne le code de sortie et la fin de la sortie du processus
        try {
            $this->runner('silent')->run($this->scenarioFile(), new ScenarioDecisions());
            self::fail('Une ProbeException était attendue.');
        } catch (ProbeException $e) {
            self::assertStringContainsString('aucun résultat (code de sortie 3)', $e->getMessage());
            self::assertStringContainsString('boom', $e->getMessage());
            self::assertStringContainsString('sortie partielle', $e->getMessage());
        }
    }

    #[Test]
    public function testRejectsAnUnreadableResultFile(): void
    {
        // ÉTANT DONNÉ une sonde qui écrit un JSON invalide
        // QUAND on la lance
        // ALORS l'erreur dit que le résultat est illisible
        $this->expectException(ProbeException::class);
        $this->expectExceptionMessage('illisible');

        $this->runner('garbage')->run($this->scenarioFile(), new ScenarioDecisions());
    }

    #[Test]
    public function testInterruptsAProbeThatExceedsTheTimeout(): void
    {
        // ÉTANT DONNÉ une sonde qui dort 10 secondes et un délai d'une seconde
        $started = microtime(true);

        // QUAND on la lance
        // ALORS elle est interrompue avec un message de délai
        try {
            $this->runner('sleep', [], 1)->run($this->scenarioFile(), new ScenarioDecisions());
            self::fail('Une ProbeException était attendue.');
        } catch (ProbeException $e) {
            self::assertStringContainsString('Délai de 1 s dépassé', $e->getMessage());
            self::assertLessThan(5.0, microtime(true) - $started);
        }
    }

    #[Test]
    public function testRejectsAMissingScenario(): void
    {
        // ÉTANT DONNÉ un chemin de scénario inexistant
        // QUAND on lance la sonde
        // ALORS une ProbeException le signale
        $this->expectException(ProbeException::class);
        $this->expectExceptionMessage('Scénario introuvable');

        $this->runner('ok')->run('/nonexistent/scenario.md', new ScenarioDecisions());
    }

    #[Test]
    public function testExplainsAMissingProbeClass(): void
    {
        // ÉTANT DONNÉ un projet sans classe de sonde, avec la commande PHPUnit par défaut
        $runner = new PhpUnitProbeRunner(sys_get_temp_dir());

        // QUAND on lance la sonde
        // ALORS l'erreur nomme la classe à créer et renvoie à la documentation.
        $this->expectException(ProbeException::class);
        $this->expectExceptionMessage('Créez MikaScenarioProbe');

        $runner->run($this->scenarioFile(), new ScenarioDecisions());
    }

    #[Test]
    public function testLeavesNoTemporaryFileBehind(): void
    {
        // ÉTANT DONNÉ les fichiers temporaires de sonde présents avant l'exécution
        $before = glob(sys_get_temp_dir() . '/mika_{result,decisions}_*', GLOB_BRACE) ?: [];
        $decisions = (new ScenarioDecisions())->with(1, new ScannedElement(tag: 'a', selector: '#a'));

        // QUAND on lance la sonde avec des décisions, puis une sonde qui échoue
        $this->runner('ok')->run($this->scenarioFile(), $decisions);
        try {
            $this->runner('silent')->run($this->scenarioFile(), $decisions);
        } catch (ProbeException) {
            // attendu
        }

        // ALORS aucun fichier de sortie ni de décisions ne subsiste
        self::assertSame($before, glob(sys_get_temp_dir() . '/mika_{result,decisions}_*', GLOB_BRACE) ?: []);
    }

    /**
     * @param array<string, string> $environment
     */
    private function runner(string $mode, array $environment = [], int $timeout = 20): PhpUnitProbeRunner
    {
        return new PhpUnitProbeRunner(
            __DIR__ . '/Fixture',
            timeoutSeconds: $timeout,
            environment: $environment + ['FAKE_PROBE_MODE' => $mode],
            command: [PHP_BINARY, __DIR__ . '/Fixture/fake-probe.php'],
        );
    }

    private function scenarioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mika_scenario_');
        file_put_contents($path, "# T\n");

        return $this->files[] = $path;
    }
}
