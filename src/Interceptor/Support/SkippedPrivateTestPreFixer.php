<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** @noinspection PhpUnused */
#[AsTaggedItem(priority: 100)]
final readonly class SkippedPrivateTestPreFixer implements TestPreFixerInterface
{
    public function __construct(
        private SkippedTestScanner $scanner,
        private CoveredClassReader $coveredClass,
    ) {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        $class = $this->coveredClass->read($testCode);
        if (null === $class || !class_exists($class)) {
            return null;
        }

        $spans = []; // position de début → position de fin de chaque test visant une méthode non publique
        foreach ($this->scanner->scan($testCode, new \ReflectionClass($class)) as $hit) {
            if (!$hit['target']->isPublic()) {
                $spans[$hit['test']->getStartFilePos()] = $hit['test']->getEndFilePos();
            }
        }
        if ([] === $spans) {
            return null;
        }

        krsort($spans); // de la fin vers le début : les positions restent valides
        foreach ($spans as $start => $end) {
            $testCode = $this->cut($testCode, $start, $end);
        }

        return $testCode;
    }

    private function cut(string $code, int $start, int $end): string
    {
        // Début de ligne : on reprend l'indentation de `#[Test]` (l'attribut fait partie du nœud)
        while ($start > 0 && \in_array($code[$start - 1], [' ', "\t"], true)) {
            --$start;
        }
        // Fin de ligne et lignes vides qui suivent : la ligne suivante garde son indentation
        if (1 === preg_match('/\G[ \t]*\R(?:[ \t]*\R)*/', $code, $m, 0, $end + 1)) {
            $end += \strlen($m[0]);
        }

        $code = substr_replace($code, '', $start, $end - $start + 1);

        // Dernière méthode retirée : pas de ligne vide avant l'accolade de fin de classe
        return (string) preg_replace('/\R(?:[ \t]*\R)+(?=}\s*\z)/', "\n", $code);
    }
}