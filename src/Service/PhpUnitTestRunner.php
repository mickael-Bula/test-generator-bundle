<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Ce service écrit temporairement le code sur le disque
 * et exécute PHPUnit via le composant Process de Symfony.
 */
readonly class PhpUnitTestRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    /**
     * @return array{success: bool, output: string}
     */
    public function runTest(string $testCode, string $className): array
    {
        // On détermine le chemin du fichier, isolé dans un dossier "Dynamic".
        $testFilePath = sprintf('%s/tests/Dynamic/%sDynamicTest.php', $this->projectDir, $className);

        // On s'assure que le dossier existe
        $dir = dirname($testFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Le dossier "%s" n\'a pas été créé', $dir));
        }

        // Écriture du code généré
        file_put_contents($testFilePath, $testCode);

        // Utilisation de DIRECTORY_SEPARATOR pour avoir 'vendor\bin\phpunit' sur Windows et 'vendor/bin/phpunit' ailleurs
        $phpunitBin = 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';

        // Exécution de PHPUnit ciblé sur ce fichier précis
        $process = new Process([
            $phpunitBin,
            $testFilePath,
        ], $this->projectDir, [
            'XDEBUG_MODE' => 'off',
            'XDEBUG_SESSION' => null,
        ]);

        $process->run();

        // On récupère la sortie (Stderr en cas d'échec, sinon Output standard de PHPUnit).
        $output = $process->getOutput() ?: $process->getErrorOutput();

        // On supprime le fichier devenu inutile.
        if (file_exists($testFilePath)) {
            unlink($testFilePath);
        }

        // On retourne true en cas de succès, sinon false.
        return [
            'success' => $process->isSuccessful(),
            'output' => $output,
        ];
    }
}
