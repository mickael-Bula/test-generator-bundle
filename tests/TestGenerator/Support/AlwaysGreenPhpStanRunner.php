<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Support;

use Mika\TestGeneratorBundle\Service\PhpStanRunner;

/** Faux PHPStan : toujours vert, sans sous-processus. */
final readonly class AlwaysGreenPhpStanRunner extends PhpStanRunner
{
    public function __construct()
    {
        parent::__construct(sys_get_temp_dir());
    }

    public function analyze(string $filePath): array
    {
        return ['success' => true, 'output' => ''];
    }
}
