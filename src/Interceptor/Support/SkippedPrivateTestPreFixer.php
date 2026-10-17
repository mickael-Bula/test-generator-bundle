<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class SkippedPrivateTestPreFixer implements TestPreFixerInterface
{
    private const SECTION = '/There w(?:as|ere) \d+ skipped tests?:\R\R(?<body>.*?)(?:\R--\R|\z)/s';
    private const ENTRY = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)\R(?<reason>[^\r\n]*)/m';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private Parser $parser,
    ) {
    }

    /**
     * @throws \ReflectionException
     */
    public function fix(string $errorOutput, string $testCode): ?string
    {
        if (1 !== preg_match(self::SECTION, $errorOutput, $section)) {
            return null;
        }
        $sut = $this->locator->locate($errorOutput)->sut;
        if (null === $sut || !class_exists($sut)) {
            return null;
        }
        $rc = new \ReflectionClass($sut);

        preg_match_all(self::ENTRY, $section['body'], $entries, PREG_SET_ORDER);
        $toRemove = [];
        foreach ($entries as $e) {
            // Méthodes du SUT qui sont citées dans la raison (« Méthode processSingleDay est privée »)
            preg_match_all('/\b[A-Za-z_]\w*\b/', $e['reason'], $words);
            $targets = array_filter(array_unique($words[0]), static fn (string $w): bool => $rc->hasMethod($w));

            $onlyNonPublic = [] !== $targets
                && [] === array_filter(/**
                 * @throws \ReflectionException
                 */ /**
                 * @throws \ReflectionException
                 */ /**
                 * @throws \ReflectionException
                 */ $targets, static fn (string $w): bool => $rc->getMethod($w)->isPublic());
            if ($onlyNonPublic) {
                $toRemove[$e['test']] = true;
            }
        }

        return [] === $toRemove ? null : $this->removeTests($testCode, $toRemove);
    }

    /** @param array<string, true> $names */
    private function removeTests(string $code, array $names): ?string
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return null;
        }

        $spans = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Stmt\ClassMethod::class) as $method) {
            if (isset($names[$method->name->toString()])) {
                // Le nœud commence à `#[Test]` : l'attribut en fait partie
                $spans[$method->getStartFilePos()] = $method->getEndFilePos();
            }
        }
        if ([] === $spans) {
            return null;
        }

        krsort($spans); // de la fin vers le début : les positions restent valides
        foreach ($spans as $start => $end) {
            while ($start > 0 && \in_array($code[$start - 1], [' ', "\t"], true)) {
                --$start; // reprend l'indentation de la ligne
            }
            $code = substr_replace($code, '', $start, $end - $start + 1);
        }

        return (string) preg_replace('/(\R[ \t]*){3,}/', "\n\n", $code);
    }
}