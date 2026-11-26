<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Dto\TestRunResult;
use Mika\TestGeneratorBundle\Functional\Exception\VerificationException;
use Mika\TestGeneratorBundle\Functional\Verification\GeneratedTestRunnerInterface;

/**
 * Exécuteur de test scénarisé : renvoie un résultat fixé (ou lève une exception) et mémorise les fichiers reçus.
 */
final class FakeGeneratedTestRunner implements GeneratedTestRunnerInterface
{
    /** @var list<string> */
    public array $files = [];

    public function __construct(
        private readonly TestRunResult|\Throwable $outcome = new TestRunResult(true, 0, "OK (1 test, 2 assertions)\n"),
    ) {
    }

    public function run(string $testFile): TestRunResult
    {
        $this->files[] = $testFile;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
