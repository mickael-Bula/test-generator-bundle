<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

use Mika\TestGeneratorBundle\Service\PhpUnitTestRunner;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;

final readonly class SoftAssertionCollector
{
    public function __construct(
        private SoftAssertionRewriter $rewriter,
        private PhpUnitTestRunner $runner,
        private TestSnapshotStore $snapshots,
    ) {
    }

    /**
     * Rejoue la classe avec des assertions molles pour les tests demandés.
     *
     * @param list<string>                           $tests
     * @param (callable(string, ?string): void)|null $debug reçoit la sortie brute de PHPUnit et l'éventuelle erreur
     *
     * @return array<string, list<array{line: int, source: string, message: string, diff: string}>>
     */
    public function collect(string $testCode, string $className, array $tests, ?callable $debug = null): array
    {
        $soft = $this->rewriter->rewrite($testCode, $tests);
        if (null === $soft) {
            if (null !== $debug) {
                $debug('', 'réécriture impossible : code illisible ou aucune assertion de premier niveau dans ' . implode(', ', $tests));
            }

            return [];
        }

        $output = '';
        $error = null;
        try {
            $output = $this->runner->runTest($soft['code'], $className)['output'];
        } catch (\Throwable $e) {
            $error = $e::class . ' : ' . $e->getMessage();
        } finally {
            // Le runner a gardé l'instantané du code assoupli : les lignes des indices doivent viser le code réel.
            $this->snapshots->save($className . 'DynamicTest.php', $testCode);
        }
        if (null !== $debug) {
            $debug($output, $error);
        }

        $found = [];
        foreach (SoftAssertionRewriter::parseOutput($output) as $test => $entries) {
            foreach ($entries as [$line, $message, $diff]) {
                $found[$test][] = ['line' => $line, 'source' => $soft['sources'][$test][$line] ?? '', 'message' => $message, 'diff' => $diff];
            }
        }

        return $found;
    }
}
