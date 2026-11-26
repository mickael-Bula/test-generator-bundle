<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Choice\NullStepChooser;
use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Enum\GenerationStatus;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionMethod;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use Mika\TestGeneratorBundle\Functional\Generation\FunctionalTestGenerator;
use Mika\TestGeneratorBundle\Functional\Render\RenderOptions;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ExecutorProbeRunner;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakeStepChooser;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ScriptedProbeRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionalTestGenerator::class)]
final class FunctionalTestGeneratorTest extends TestCase
{
    private const AMBIGUOUS = "# Modifier l'objectif\n\n## Étapes\n1. Cliquer sur « Modifier l'objectif Core »\n\n## Attentes\n- L'URL est /\n";

    /** @var list<string> */
    private array $files = [];

    private RenderOptions $options;

    protected function setUp(): void
    {
        $this->options = new RenderOptions('App\Tests\Dynamic\Functional', 'App\Tests\E2E\E2ETest');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Test]
    public function testGeneratesWithoutTheChooserWhenTheRulesResolveEverything(): void
    {
        // ÉTANT DONNÉ le scénario de création, que les règles résolvent seules
        $runner = new ExecutorProbeRunner();
        $chooser = new FakeStepChooser();
        $generator = new FunctionalTestGenerator($runner, $chooser);

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile($this->creation()), $this->options);

        // ALORS le test est produit en une exécution de sonde, toutes les étapes sont résolues par règle
        self::assertSame(GenerationStatus::Generated, $report->status, (string) $report->error);
        self::assertSame(1, $report->probeRuns);
        self::assertSame(0, $chooser->calls);
        self::assertCount(6, $report->steps);
        self::assertSame([ResolutionMethod::Rule], array_values(array_unique(array_map(static fn ($s) => $s->method, $report->steps), SORT_REGULAR)));
        self::assertSame('CreerUnePositionEnAttenteDynamicTest', $report->className);
        self::assertStringContainsString('final class CreerUnePositionEnAttenteDynamicTest extends E2ETest', (string) $report->source);
        self::assertSame([], $report->warnings);
    }

    #[Test]
    public function testAsksTheChooserForAnAmbiguousStepAndReplaysWithItsChoice(): void
    {
        // ÉTANT DONNÉ une étape ambiguë entre deux boutons, et un choix qui retient le premier
        $runner = new ExecutorProbeRunner();
        $chooser = new FakeStepChooser(0);
        $generator = new FunctionalTestGenerator($runner, $chooser);

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS la sonde est relancée avec la décision, l'étape est rapportée « LLM » et le sélecteur fragile signalé
        self::assertSame(GenerationStatus::Generated, $report->status, (string) $report->error);
        self::assertSame(2, $report->probeRuns);
        self::assertSame(1, $chooser->calls);
        self::assertTrue($runner->decisionsSeen[0]->isEmpty());
        self::assertSame([1], $runner->decisionsSeen[1]->stepIndexes());
        self::assertSame(ResolutionMethod::Llm, $report->steps[0]->method);
        self::assertSame($runner->decisionsSeen[1]->get(1)?->selector, $report->steps[0]->selector);
        self::assertTrue($report->steps[0]->fragile);
        self::assertStringContainsString('sélecteur fragile', $report->warnings[0]);
    }

    #[Test]
    public function testStopsWithTheCandidatesWhenTheChooserDeclines(): void
    {
        // ÉTANT DONNÉ un choix qui ne retient aucun candidat
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), new FakeStepChooser(null));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS la génération s'arrête sur l'étape 1 avec ses deux candidats, sans code
        self::assertSame(GenerationStatus::NeedsDecision, $report->status);
        self::assertNull($report->source);
        self::assertSame(1, $report->pendingStep?->index);
        self::assertCount(2, $report->pending?->candidates ?? []);
        self::assertSame(1, $report->probeRuns);
    }

    #[Test]
    public function testAnExplicitChooserReplacesTheConfiguredOne(): void
    {
        // ÉTANT DONNÉ un générateur configuré avec un choix actif
        $chooser = new FakeStepChooser(0);
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), $chooser);

        // QUAND on génère en imposant le choix nul (--no-llm)
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options, new NullStepChooser());

        // ALORS le choix configuré n'est pas sollicité
        self::assertSame(GenerationStatus::NeedsDecision, $report->status);
        self::assertSame(0, $chooser->calls);
    }

    #[Test]
    public function testReportsAChooserFailureWithoutGuessing(): void
    {
        // ÉTANT DONNÉ un choix qui échoue (réponse du LLM inexploitable, par exemple)
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), new FakeStepChooser(0, new \RuntimeException('réponse illisible')));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS l'échec nomme l'étape et la cause
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('Étape 1 : choix impossible : réponse illisible', (string) $report->error);
        self::assertNull($report->source);
        self::assertSame(1, $report->pendingStep?->index);
        self::assertCount(2, $report->pending?->candidates ?? []);
        self::assertSame('/', $report->pending?->path);
    }

    #[Test]
    public function testGivesUpAfterTheMaximumNumberOfDecisions(): void
    {
        // ÉTANT DONNÉ deux étapes ambiguës et une seule décision autorisée
        $markdown = "# Deux fois\n\n## Étapes\n1. Cliquer sur « Modifier l'objectif Core »\n2. Cliquer sur « Modifier l'objectif Core »\n\n## Attentes\n- L'URL est /\n";
        $chooser = new FakeStepChooser(0);
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), $chooser);

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile($markdown), $this->options, null, 1);

        // ALORS l'abandon est signalé à la deuxième étape, sans second appel au choix
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('Étape 2 : plus de 1 décisions nécessaires', (string) $report->error);
        self::assertSame(1, $chooser->calls);
    }

    #[Test]
    public function testFailsWhenAnExpectationIsNotMet(): void
    {
        // ÉTANT DONNÉ un scénario dont l'attente d'URL est fausse
        $markdown = str_replace("L'URL est /", "L'URL est /introuvable", self::AMBIGUOUS);
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), new FakeStepChooser(0));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile($markdown), $this->options);

        // ALORS l'échec de la sonde est rapporté avec l'attente concernée et les étapes déjà résolues
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('Attente 1', (string) $report->error);
        self::assertCount(1, $report->steps);
    }

    #[Test]
    public function testPassesTheProbeWarningsOn(): void
    {
        // ÉTANT DONNÉ une attente de texte que la sonde ne retrouve pas dans la page
        $markdown = self::AMBIGUOUS . "- Le texte « Phrase introuvable » est affiché\n";
        $generator = new FunctionalTestGenerator(new ExecutorProbeRunner(), new FakeStepChooser(0));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile($markdown), $this->options);

        // ALORS le test est produit et l'avertissement de la sonde est repris
        self::assertSame(GenerationStatus::Generated, $report->status, (string) $report->error);
        self::assertStringContainsString('Phrase introuvable', implode("\n", $report->warnings));
    }

    #[Test]
    public function testReportsAProbeThatCouldNotRun(): void
    {
        // ÉTANT DONNÉ une sonde qui ne peut pas s'exécuter
        $generator = new FunctionalTestGenerator(new ScriptedProbeRunner([new ProbeException('Délai de 120 s dépassé')]));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS l'échec reprend le message, sans exécution comptée
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertSame('Délai de 120 s dépassé', $report->error);
        self::assertSame(0, $report->probeRuns);
    }

    #[Test]
    public function testReportsAScenarioThatDoesNotParse(): void
    {
        // ÉTANT DONNÉ un fichier qui n'est pas un scénario
        $runner = new ScriptedProbeRunner([ProbeResult::ok([], [])]);
        $generator = new FunctionalTestGenerator($runner);

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile("rien d'utile\n"), $this->options);

        // ALORS l'échec vient du parseur et la sonde n'est jamais lancée
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertSame(0, $runner->runs);
        self::assertSame('', $report->title);
    }

    #[Test]
    public function testFailsWhenThereIsNoCandidateAtAll(): void
    {
        // ÉTANT DONNÉ une sonde qui demande une décision sans aucun candidat
        $chooser = new FakeStepChooser(0);
        $runner = new ScriptedProbeRunner([ProbeResult::needsDecision([], new PendingDecision(1, ResolutionStatus::NotFound, []))]);
        $generator = new FunctionalTestGenerator($runner, $chooser);

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS l'échec dit qu'aucun élément n'est compatible, sans solliciter le choix
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('aucun élément compatible', (string) $report->error);
        self::assertSame(0, $chooser->calls);
    }

    #[Test]
    public function testFailsWhenTheProbeAsksAgainAboutTheSameStep(): void
    {
        // ÉTANT DONNÉ une sonde qui redemande la même étape après la décision
        $pending = new PendingDecision(1, ResolutionStatus::Ambiguous, [new ScannedElement(tag: 'button', selector: '#a')]);
        $runner = new ScriptedProbeRunner([ProbeResult::needsDecision([], $pending)]);
        $generator = new FunctionalTestGenerator($runner, new FakeStepChooser(0));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS la boucle s'arrête au lieu de tourner
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('redemande une décision', (string) $report->error);
        self::assertSame(2, $runner->runs);
    }

    #[Test]
    public function testFailsWhenTheProbeAsksForADecisionWithoutDescribingIt(): void
    {
        // ÉTANT DONNÉ un résultat « needs_decision » sans description
        $runner = new ScriptedProbeRunner([new ProbeResult(ProbeStatus::NeedsDecision, [], [])]);
        $generator = new FunctionalTestGenerator($runner);

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS l'échec le dit
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('sans la décrire', (string) $report->error);
    }

    #[Test]
    public function testRenderingErrorsBecomeAFailedReport(): void
    {
        // ÉTANT DONNÉ un résultat « ok » qui ne décrit pas l'étape du scénario
        $generator = new FunctionalTestGenerator(new ScriptedProbeRunner([ProbeResult::ok([], [])]));

        // QUAND on génère
        $report = $generator->generate($this->scenarioFile(self::AMBIGUOUS), $this->options);

        // ALORS le gabarit refuse et l'échec nomme l'étape
        self::assertSame(GenerationStatus::Failed, $report->status);
        self::assertStringContainsString('n\'a pas résolu l\'étape 1', (string) $report->error);
    }

    private function creation(): string
    {
        return (string) (new \ReflectionClassConstant(ScenarioExecutorTest::class, 'CREATION'))->getValue();
    }

    private function scenarioFile(string $markdown): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mika_gen_');
        file_put_contents($path, $markdown);

        return $this->files[] = $path;
    }
}
