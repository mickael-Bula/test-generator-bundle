<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Verification;

use Mika\TestGeneratorBundle\Functional\Dto\TestRunResult;
use Mika\TestGeneratorBundle\Functional\Exception\VerificationException;

/**
 * Exécute le test généré pour savoir s'il est réellement vert.
 */
interface GeneratedTestRunnerInterface
{
    /**
     * @throws VerificationException si l'exécution n'a pas pu être lancée
     */
    public function run(string $testFile): TestRunResult;
}
