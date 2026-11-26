<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Probe;

use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use Mika\TestGeneratorBundle\Functional\Process\ProcessExecutor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lance la sonde dans un processus PHPUnit de l'hôte (navigateur, noyau Symfony et base de test y sont disponibles).
 *
 * Contrat avec la classe de sonde de l'hôte (voir ScenarioProbeTrait) : le scénario, le fichier de sortie
 * et les décisions éventuelles lui sont transmis par variables d'environnement ; le résultat revient
 * dans le fichier de sortie, en JSON. La base de test est restaurée par DAMA à chaque exécution.
 */
final class PhpUnitProbeRunner implements ProbeRunnerInterface
{
    private const TAIL_LINES = 30;

    /**
     * @param array<string, string> $environment variables ajoutées à l'environnement du processus
     * @param list<string>|null     $command     remplace la commande PHPUnit complète (tests)
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly string $probeFile = 'tests/E2E/MikaScenarioProbe.php',
        private readonly string $phpunitBinary = 'vendor/bin/phpunit',
        private readonly int $timeoutSeconds = 120,
        private readonly array $environment = ['APP_ENV' => 'test', 'PLAYWRIGHT_E2E' => '1', 'XDEBUG_MODE' => 'off'],
        private readonly ?array $command = null,
        private readonly ProcessExecutor $executor = new ProcessExecutor(),
    ) {
    }

    public function run(string $scenarioPath, ScenarioDecisions $decisions): ProbeResult
    {
        $command = $this->command ?? $this->phpunitCommand();
        $output = $this->tempFile('result', '');
        $decisionsFile = $decisions->isEmpty() ? null : $this->tempFile('decisions', $decisions->toJson());

        try {
            $scenario = realpath($scenarioPath);
            if (false === $scenario) {
                throw new ProbeException(sprintf('Scénario introuvable : %s', $scenarioPath));
            }

            try {
                $process = $this->executor->run($command, $this->projectDir, $this->processEnvironment($scenario, $output, $decisionsFile), $this->timeoutSeconds);
            } catch (\RuntimeException) {
                throw new ProbeException('Impossible de lancer le processus de la sonde.');
            }

            if ($process->timedOut) {
                throw new ProbeException(sprintf(
                    "Délai de %d s dépassé : la sonde a été interrompue.\nSortie du processus (fin) :\n%s",
                    $this->timeoutSeconds,
                    $process->tail(self::TAIL_LINES),
                ));
            }

            $json = (string) file_get_contents($output);
            if ('' === trim($json)) {
                throw new ProbeException(sprintf(
                    "La sonde n'a produit aucun résultat (code de sortie %d).\nSortie du processus (fin) :\n%s",
                    $process->exitCode,
                    $process->tail(self::TAIL_LINES),
                ));
            }

            return ProbeResult::fromJson($json);
        } finally {
            $this->remove($output);
            if (null !== $decisionsFile) {
                $this->remove($decisionsFile);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpunitCommand(): array
    {
        $probe = $this->absolute($this->probeFile);
        if (!is_file($probe)) {
            throw new ProbeException(sprintf('Classe de sonde introuvable : %s. Créez MikaScenarioProbe dans l\'hôte (voir docs/functional-tests.md).', $probe));
        }

        $phpunit = $this->absolute($this->phpunitBinary);
        if (!is_file($phpunit)) {
            throw new ProbeException(sprintf('PHPUnit introuvable : %s', $phpunit));
        }

        return [PHP_BINARY, $phpunit, '--colors=never', '--do-not-cache-result', '--filter', 'testProbe', $probe];
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(string $scenario, string $output, ?string $decisionsFile): array
    {
        $env = array_merge(
            array_map('strval', getenv()),
            $this->environment,
            ['MIKA_PROBE_SCENARIO' => $scenario, 'MIKA_PROBE_OUTPUT' => $output],
        );

        if (null === $decisionsFile) {
            unset($env['MIKA_PROBE_DECISIONS']);
        } else {
            $env['MIKA_PROBE_DECISIONS'] = $decisionsFile;
        }

        return $env;
    }

    private function absolute(string $path): string
    {
        return 1 === preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) ? $path : rtrim($this->projectDir, '/\\') . '/' . $path;
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function tempFile(string $name, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mika_' . $name . '_');
        if (false === $path) {
            throw new ProbeException('Impossible de créer un fichier temporaire pour la sonde.');
        }

        file_put_contents($path, $content);

        return $path;
    }
}
