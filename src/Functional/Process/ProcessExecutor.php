<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Process;

use Mika\TestGeneratorBundle\Functional\Dto\ProcessResult;

/**
 * Lance une commande (sans passer par un shell) avec un délai maximal et capture sa sortie.
 */
class ProcessExecutor
{
    private const MAX_LOG_BYTES = 65536;

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @throws \RuntimeException si le processus ne peut pas être lancé
     */
    public function run(array $command, string $workingDirectory, array $env, int $timeoutSeconds): ProcessResult
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Impossible de lancer le processus.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $log = '';
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            while (true) {
                $log = $this->drain($pipes, $log);
                $status = proc_get_status($process);

                if (!$status['running']) {
                    return new ProcessResult($status['exitcode'], $this->drain($pipes, $log));
                }

                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    usleep(200000);
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }

                    return new ProcessResult(-1, $this->drain($pipes, $log), true);
                }

                usleep(20000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function drain(array $pipes, string $log): string
    {
        foreach ([1, 2] as $index) {
            while (true) {
                $chunk = fread($pipes[$index], 8192);
                if (!is_string($chunk) || '' === $chunk) {
                    break;
                }

                $log .= $chunk;
            }
        }

        return strlen($log) > self::MAX_LOG_BYTES ? substr($log, -self::MAX_LOG_BYTES / 2) : $log;
    }
}
