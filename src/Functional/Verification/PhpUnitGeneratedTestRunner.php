<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Verification;

use Mika\TestGeneratorBundle\Functional\Dto\TestRunResult;
use Mika\TestGeneratorBundle\Functional\Exception\VerificationException;
use Mika\TestGeneratorBundle\Functional\Process\ProcessExecutor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lance PHPUnit sur le seul fichier généré, dans l'hôte, avec le même environnement que la sonde.
 *
 * « Vert » est strict : code de sortie 0 ET bilan « OK (N tests, M assertions) ». Un test ignoré
 * (« OK, but some tests were skipped! »), risqué ou accompagné d'avertissements n'a rien vérifié :
 * il n'est pas considéré comme vert.
 */
final class PhpUnitGeneratedTestRunner implements GeneratedTestRunnerInterface
{
    /**
     * @param array<string, string> $environment    variables ajoutées à l'environnement du processus
     * @param list<string>|null     $phpunitCommand remplace « php vendor/bin/phpunit » (tests)
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly string $phpunitBinary = 'vendor/bin/phpunit',
        private readonly int $timeoutSeconds = 300,
        private readonly array $environment = ['APP_ENV' => 'test', 'PLAYWRIGHT_E2E' => '1', 'XDEBUG_MODE' => 'off'],
        private readonly ?array $phpunitCommand = null,
        private readonly ProcessExecutor $executor = new ProcessExecutor(),
    ) {
    }

    public function run(string $testFile): TestRunResult
    {
        if (!is_file($testFile)) {
            throw new VerificationException(sprintf('Test introuvable : %s', $testFile));
        }

        $command = [...$this->baseCommand(), '--colors=never', '--do-not-cache-result', $testFile];
        $env = array_merge(array_map('strval', getenv()), $this->environment);
        unset($env['MIKA_PROBE_SCENARIO'], $env['MIKA_PROBE_OUTPUT'], $env['MIKA_PROBE_DECISIONS']);

        try {
            $process = $this->executor->run($command, $this->projectDir, $env, $this->timeoutSeconds);
        } catch (\RuntimeException $e) {
            throw new VerificationException('Impossible de lancer PHPUnit : ' . $e->getMessage(), 0, $e);
        }

        if ($process->timedOut) {
            return new TestRunResult(
                false,
                $process->exitCode,
                sprintf("Délai de %d s dépassé : PHPUnit a été interrompu.\n%s", $this->timeoutSeconds, $process->output),
                true,
            );
        }

        $green = 0 === $process->exitCode && 1 === preg_match('/^OK \(\d+ tests?, \d+ assertions?\)/m', $process->output);

        return new TestRunResult($green, $process->exitCode, $process->output);
    }

    /**
     * @return list<string>
     */
    private function baseCommand(): array
    {
        if (null !== $this->phpunitCommand) {
            return $this->phpunitCommand;
        }

        $phpunit = 1 === preg_match('#^(/|[A-Za-z]:[\\\\/])#', $this->phpunitBinary)
            ? $this->phpunitBinary
            : rtrim($this->projectDir, '/\\') . '/' . $this->phpunitBinary;

        if (!is_file($phpunit)) {
            throw new VerificationException(sprintf('PHPUnit introuvable : %s', $phpunit));
        }

        return [PHP_BINARY, $phpunit];
    }
}
