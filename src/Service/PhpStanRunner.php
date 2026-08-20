<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Symfony\Component\Process\Process;

class PhpStanRunner
{
    /**
     * @return array{success: bool, output: string}
     *
     * @throws \JsonException
     */
    public function analyze(string $filePath): array
    {
        // Exécution de phpstan sur le fichier généré avec sortie JSON
        $process = new Process([
            'vendor/bin/phpstan',
            'analyse',
            $filePath,
            '--level=8',
            '--error-format=json',
            '--no-progress',
        ]);

        $process->run();
        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $errors = [];
        if (isset($output['files'][$filePath]['messages'])) {
            foreach ($output['files'][$filePath]['messages'] as $message) {
                $errors[] = sprintf('Ligne %d: %s', $message['line'], $message['message']);
            }
        }

        return [
            'success' => empty($errors),
            'output' => implode("\n", $errors),
        ];
    }
}
