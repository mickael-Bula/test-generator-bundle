<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Support\ScenarioProbeTrait;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ContractE2ETest;
use Mika\TestGeneratorBundle\Tests\Functional\Support\FakePage;
use Mika\TestGeneratorBundle\Tests\Functional\Support\ProbeHostStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScenarioProbeTrait::class)]
final class ScenarioProbeTraitTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        ContractE2ETest::$logins = [];
        ContractE2ETest::$visited = [];
        ContractE2ETest::$counts = [];
    }

    protected function tearDown(): void
    {
        foreach (['MIKA_PROBE_SCENARIO', 'MIKA_PROBE_OUTPUT', 'MIKA_PROBE_DECISIONS'] as $name) {
            putenv($name);
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Test]
    public function testWritesAnOkResultForAResolvableScenario(): void
    {
        // ÉTANT DONNÉ un scénario de navigation, une page qui renvoie le vrai balayage du tableau de bord
        $scenario = $this->file("# Aller aux paramètres\n\n## Contexte\n- Authentification : mot de passe, profil « trader »\n\n## Étapes\n1. Cliquer sur le lien « Paramètres »\n\n## Attentes\n- L'URL est /\n");
        $output = $this->file('');
        $this->env($scenario, $output);
        $scan = json_decode((string) file_get_contents(__DIR__ . '/Fixture/scan-dashboard.json'), true, 512, JSON_THROW_ON_ERROR);
        ContractE2ETest::$page = new FakePage(scan: $scan);

        // QUAND on exécute la sonde
        (new ProbeHostStub('run'))->run();

        // ALORS le résultat écrit est « ok », la connexion a eu lieu avec le profil du scénario et le clic a visé le lien
        $result = ProbeResult::fromJson((string) file_get_contents($output));
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertSame('a[href="/settings"]', $result->stepFor(1)?->target?->selector);
        self::assertSame(['trader'], ContractE2ETest::$logins);
        self::assertSame(['/'], ContractE2ETest::$visited);
        self::assertSame(['a[href="/settings"] :: click'], ContractE2ETest::$page->log->getArrayCopy());
    }

    #[Test]
    public function testAppliesTheDecisionsFileToAnAmbiguousStep(): void
    {
        // ÉTANT DONNÉ un libellé qui ne correspond à aucun élément, et une décision désignant le lien « Portefeuille »
        $scenario = $this->file("# Aller ailleurs\n\n## Étapes\n1. Cliquer sur le lien « Mon espace »\n\n## Attentes\n- L'URL est /\n");
        $output = $this->file('');
        $decisions = $this->file(json_encode(['1' => ['tag' => 'a', 'href' => '/portfolio', 'text' => 'Portefeuille', 'selector' => 'a[href="/portfolio"]', 'relative' => 'a[href="/portfolio"]']], JSON_THROW_ON_ERROR));
        $this->env($scenario, $output, $decisions);
        $scan = json_decode((string) file_get_contents(__DIR__ . '/Fixture/scan-dashboard.json'), true, 512, JSON_THROW_ON_ERROR);
        ContractE2ETest::$page = new FakePage(scan: $scan);

        // QUAND on exécute la sonde
        (new ProbeHostStub('run'))->run();

        // ALORS la décision est appliquée sans demander de nouvelle décision
        $result = ProbeResult::fromJson((string) file_get_contents($output));
        self::assertSame(ProbeStatus::Ok, $result->status, (string) $result->error);
        self::assertSame(['a[href="/portfolio"] :: click'], ContractE2ETest::$page->log->getArrayCopy());
    }

    #[Test]
    public function testWritesANeedsDecisionResultWithoutDecisions(): void
    {
        // ÉTANT DONNÉ le même libellé introuvable, sans fichier de décisions
        $scenario = $this->file("# Aller ailleurs\n\n## Étapes\n1. Cliquer sur le lien « Mon espace »\n\n## Attentes\n- L'URL est /\n");
        $output = $this->file('');
        $this->env($scenario, $output);
        $scan = json_decode((string) file_get_contents(__DIR__ . '/Fixture/scan-dashboard.json'), true, 512, JSON_THROW_ON_ERROR);
        ContractE2ETest::$page = new FakePage(scan: $scan);

        // QUAND on exécute la sonde
        (new ProbeHostStub('run'))->run();

        // ALORS la décision attendue est décrite dans le fichier de sortie et rien n'a été cliqué
        $result = ProbeResult::fromJson((string) file_get_contents($output));
        self::assertSame(ProbeStatus::NeedsDecision, $result->status);
        self::assertSame(1, $result->pending?->stepIndex);
        self::assertSame([], ContractE2ETest::$page->log->getArrayCopy());
    }

    #[Test]
    public function testAnUnreadableScenarioBecomesAFailedResultNotACrash(): void
    {
        // ÉTANT DONNÉ un chemin de scénario inexistant
        $output = $this->file('');
        $this->env('/nonexistent/scenario.md', $output);
        ContractE2ETest::$page = new FakePage();

        // QUAND on exécute la sonde
        (new ProbeHostStub('run'))->run();

        // ALORS la sortie contient un échec explicite
        $result = ProbeResult::fromJson((string) file_get_contents($output));
        self::assertSame(ProbeStatus::Failed, $result->status);
        self::assertStringContainsString('/nonexistent/scenario.md', (string) $result->error);
    }

    private function env(string $scenario, string $output, ?string $decisions = null): void
    {
        putenv('MIKA_PROBE_SCENARIO=' . $scenario);
        putenv('MIKA_PROBE_OUTPUT=' . $output);
        putenv(null === $decisions ? 'MIKA_PROBE_DECISIONS' : 'MIKA_PROBE_DECISIONS=' . $decisions);
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mika_probe_');
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }
}
