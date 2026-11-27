<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Process;

use Mika\TestGeneratorBundle\Functional\Dto\ProcessResult;

/**
 * Lance une commande (sans passer par un shell) avec un délai maximal et capture sa sortie.
 *
 * La sortie passe par un fichier temporaire plutôt que par des pipes : sous Windows, les pipes de proc_open
 * ne sont pas non bloquants et fread() empêcherait de contrôler le délai.
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
        $logFile = tempnam(sys_get_temp_dir(), 'mika_proc_');
        $errFile = false === $logFile ? false : tempnam(sys_get_temp_dir(), 'mika_proc_');
        if (false === $logFile || false === $errFile) {
            false === $logFile || @unlink($logFile);
            throw new \RuntimeException('Impossible de créer le fichier de sortie du processus.');
        }

        $pipes = [];
        $process = proc_open($command, [1 => ['file', $logFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, $workingDirectory, $env);
        if (!is_resource($process)) {
            @unlink($logFile);
            @unlink($errFile);

            throw new \RuntimeException('Impossible de lancer le processus.');
        }

        $deadline = microtime(true) + $timeoutSeconds;

        try {
            while (true) {
                $status = proc_get_status($process);

                if (!$status['running']) {
                    return new ProcessResult($status['exitcode'], $this->tail($logFile, $errFile));
                }

                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    usleep(200000);
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }

                    return new ProcessResult(-1, $this->tail($logFile, $errFile), true);
                }

                usleep(20000);
            }
        } finally {
            proc_close($process);
            @unlink($logFile);
            @unlink($errFile);
        }
    }

    private function tail(string $out, string $err): string
    {
        $log = (string) file_get_contents($out) . (string) file_get_contents($err);

        return strlen($log) > self::MAX_LOG_BYTES ? substr($log, -self::MAX_LOG_BYTES / 2) : $log;
    }
}
