<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Parser\FunctionalScenarioParser;
use Mika\TestGeneratorBundle\Functional\Runtime\PlaywrightBrowser;
use Mika\TestGeneratorBundle\Functional\Runtime\ScenarioExecutor;

/**
 * Corps de la sonde, exécuté dans un processus PHPUnit de l'hôte (c'est lui qui a le navigateur, le noyau
 * Symfony et la base de test). La commande lance ce test avec trois variables d'environnement :
 *
 *   MIKA_PROBE_SCENARIO   chemin du scénario Markdown
 *   MIKA_PROBE_OUTPUT     fichier où écrire le résultat (JSON)
 *   MIKA_PROBE_DECISIONS  (facultatif) chemin d'un fichier JSON des éléments choisis pour les étapes ambiguës
 *
 * Classe à créer dans l'hôte (le nom du fichier ne finit pas par « Test » : la suite normale l'ignore) :
 *
 *     final class MikaScenarioProbe extends E2ETest
 *     {
 *         use ScenarioProbeTrait;
 *
 *         public function testProbe(): void { $this->runScenarioProbe(); }
 *     }
 *
 * Elle doit utiliser une classe de base qui fournit loginAs(), open() et countRows() (FunctionalTestHelpersTrait).
 */
trait ScenarioProbeTrait
{
    protected function runScenarioProbe(): void
    {
        $scenarioPath = (string) getenv('MIKA_PROBE_SCENARIO');
        $outputPath = (string) getenv('MIKA_PROBE_OUTPUT');

        if ('' === $scenarioPath || '' === $outputPath) {
            self::markTestSkipped('Sonde de scénario : MIKA_PROBE_SCENARIO et MIKA_PROBE_OUTPUT ne sont pas définis.');
        }

        try {
            $scenario = (new FunctionalScenarioParser())->parseFile($scenarioPath);

            $decisionsPath = (string) getenv('MIKA_PROBE_DECISIONS');
            $decisions = '' !== $decisionsPath && is_file($decisionsPath)
                ? ScenarioDecisions::fromJson((string) file_get_contents($decisionsPath))
                : new ScenarioDecisions();

            if (null !== $scenario->context->authStrategy) {
                $this->loginAs($scenario->context->authProfile ?? 'default', $scenario->context->authStrategy);
            }

            $browser = new PlaywrightBrowser(
                fn (string $path): object => $this->open($path),
                fn (string $table): int => $this->countRows($table),
            );

            $result = (new ScenarioExecutor())->execute($scenario, $browser, $decisions);
        } catch (\Throwable $e) {
            $result = ProbeResult::failed([], sprintf('%s : %s', $e::class, $e->getMessage()));
        }

        file_put_contents($outputPath, $result->toJson());

        // La sonde n'a pas d'assertion métier : son verdict est le fichier de sortie.
        self::assertTrue(true);
    }
}
