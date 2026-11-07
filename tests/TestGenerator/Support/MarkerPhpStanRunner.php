<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Support;

use Mika\TestGeneratorBundle\Service\PhpStanRunner;

/**
 * Faux PHPStan : une erreur par ligne qui contient STAN[message], au format du vrai rapport (« - Ligne N : message »).
 * Vert quand le code ne contient aucun marqueur. Les numéros de ligne sont ceux du fichier analysé.
 */
final readonly class MarkerPhpStanRunner extends PhpStanRunner
{
    /** @var \ArrayObject<int, string> codes analysés, dans l'ordre */
    public \ArrayObject $analyzed;

    public function __construct()
    {
        parent::__construct(sys_get_temp_dir());
        $this->analyzed = new \ArrayObject();
    }

    public function analyze(string $filePath): array
    {
        $code = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($filePath));
        $this->analyzed->append($code);

        $errors = [];
        foreach (explode("\n", $code) as $index => $line) {
            if (1 === preg_match('/STAN\[([^\]]*)\]/', $line, $matches)) {
                $errors[] = sprintf('- Ligne %d : %s', $index + 1, $matches[1]);
            }
        }

        return ['success' => [] === $errors, 'output' => implode("\n", $errors)];
    }
}
