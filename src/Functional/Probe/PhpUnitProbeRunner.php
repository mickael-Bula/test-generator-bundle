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
    private const PROBE_CLASS = 'MikaScenarioProbe';

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
        private readonly string $defaultBaseClass = 'App\Tests\E2E\E2ETest',
    ) {
    }

    public function run(string $scenarioPath, ScenarioDecisions $decisions, ?string $baseClass = null): ProbeResult
    {
        $output = $this->tempFile('result', '');
        $decisionsFile = $decisions->isEmpty() ? null : $this->tempFile('decisions', $decisions->toJson());
        $generatedDir = null;
        $baseClass ??= $this->defaultBaseClass;

        try {
            if (null !== $this->command) {
                $command = $this->command;
            } else {
                [$command, $generatedDir] = $this->phpunitCommand($baseClass);
            }

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
                    "La sonde n'a produit aucun résultat (code de sortie %d).%s\nSortie du processus (fin) :\n%s",
                    $process->exitCode,
                    null === $generatedDir ? '' : sprintf("\nLa classe de sonde a été générée automatiquement à partir de la classe de base « %s » (option --base-class) ; pour utiliser la vôtre, créez %s.", $baseClass, $this->probeFile),
                    $process->tail(self::TAIL_LINES),
                ));
            }

            return ProbeResult::fromJson($json);
        } finally {
            $this->remove($output);
            if (null !== $decisionsFile) {
                $this->remove($decisionsFile);
            }
            if (null !== $generatedDir) {
                $this->remove($generatedDir . '/' . self::PROBE_CLASS . '.php');
                if (is_dir($generatedDir)) {
                    rmdir($generatedDir);
                }
            }
        }
    }

    /**
     * Commande PHPUnit de la sonde. La classe de sonde de l'hôte (probeFile) a priorité si elle existe ;
     * sinon une classe est générée dans un dossier temporaire à partir de la classe de base.
     *
     * @return array{0: list<string>, 1: ?string} la commande et le dossier temporaire à supprimer (null si la classe est celle de l'hôte)
     */
    private function phpunitCommand(string $baseClass): array
    {
        $phpunit = $this->absolute($this->phpunitBinary);
        if (!is_file($phpunit)) {
            throw new ProbeException(sprintf('PHPUnit introuvable : %s', $phpunit));
        }

        $arguments = [PHP_BINARY, $phpunit, '--colors=never', '--do-not-cache-result', '--filter', 'testProbe'];

        $own = $this->absolute($this->probeFile);
        if (is_file($own)) {
            return [[...$arguments, $own], null];
        }

        if (1 !== preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $baseClass)) {
            throw new ProbeException(sprintf('Classe de base invalide : « %s » (attendu : un nom de classe complet, ex. App\\Tests\\E2E\\E2ETest).', $baseClass));
        }

        $directory = sys_get_temp_dir() . '/mika_probe_' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ProbeException('Impossible de créer le dossier temporaire de la classe de sonde.');
        }

        $file = $directory . '/' . self::PROBE_CLASS . '.php';
        if (false === file_put_contents($file, $this->probeSource($baseClass))) {
            rmdir($directory);

            throw new ProbeException(sprintf('Impossible d\'écrire la classe de sonde : %s', $file));
        }

        return [[...$arguments, $file], $directory];
    }

    private function probeSource(string $baseClass): string
    {
        return sprintf(<<<'PHP'
<?php

declare(strict_types=1);

// Classe de sonde générée par llm:generate:functional:test ; elle est supprimée après l'exécution.
// Pour la remplacer par la vôtre, créez %s.

use Mika\TestGeneratorBundle\Functional\Support\ScenarioProbeTrait;

final class %s extends \%s
{
    use ScenarioProbeTrait;

    public function testProbe(): void
    {
        $this->runScenarioProbe();
    }
}

PHP, $this->probeFile, self::PROBE_CLASS, ltrim($baseClass, '\\'));
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
