<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Exception\PhpStanNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

readonly class PhpStanRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    /**
     * @return array{success: bool, output: string}
     */
    public function analyze(string $filePath): array
    {
        // Résolution multiplateforme du binaire de PHPStan
        $phpstanBin = 'vendor'
            . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'phpstan';

        $fullBinPath = $this->projectDir
            . DIRECTORY_SEPARATOR . $phpstanBin;

        // Sous Windows, Composer crée phpstan.bat dans vendor\bin
        $isWindows = '\\' === DIRECTORY_SEPARATOR;
        if ($isWindows && file_exists($fullBinPath . '.bat')) {
            $phpstanBin .= '.bat';
            $fullBinPath .= '.bat';
        }

        // Si PHPStan n'est pas installé dans le projet hôte, on le signale à l'utilisateur
        if (!file_exists($fullBinPath)) {
            throw PhpStanNotFoundException::create();
        }

        // Exécution ciblée sur le fichier temporaire
        $process = new Process(
            [
                $phpstanBin,
                'analyse',
                $filePath,
                '--level=6',
                '--error-format=json',
                '--no-progress',
            ],
            $this->projectDir
        );

        $process->run();

        $rawOutput = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        // 1. En cas d'échec d'exécution du processus (crash binaire, erreur système)
        if ('' === $rawOutput) {
            throw new \RuntimeException(
                'Erreur système d\'exécution PHPStan : ' . ($errorOutput ?: 'Aucune réponse retournée.')
            );
        }

        // 2. Décodage sécurisé de la réponse JSON
        try {
            $data = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                sprintf(
                    "Impossible de lire le rapport JSON de PHPStan (%s).\nSortie brute :\n%s",
                    $e->getMessage(),
                    $rawOutput
                )
            );
        }

        // 3. Normalisation de la réponse pour la boucle agentique
        $totals = $data['totals'] ?? [];
        $normalizedFilePath = realpath($filePath) ?: str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

        $fileErrors = $data['files'][$normalizedFilePath]['messages']
            ?? $data['files'][$filePath]['messages']
            ?? [];

        $hasErrors = ($totals['file_errors'] ?? 0) > 0 || !empty($fileErrors);

        if (!$hasErrors) {
            return [
                'success' => true,
                'output' => '',
            ];
        }

        // Formatage des erreurs pour le LLM (Fixer)
        $formattedErrors = [];
        foreach ($fileErrors as $error) {
            $formattedErrors[] = sprintf(
                '- Ligne %d : %s',
                $error['line'] ?? 0,
                $error['message'] ?? 'Erreur inconnue'
            );
        }

        return [
            'success' => false,
            'output' => implode("\n", $formattedErrors),
        ];
    }
}
