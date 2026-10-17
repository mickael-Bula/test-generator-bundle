<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;

/** @noinspection PhpUnused */
final readonly class SkippedTestInterceptor implements ErrorInterceptorInterface
{
    private const SECTION = '/There w(?:as|ere) \d+ skipped tests?:\R\R(?<body>.*?)(?:\R--\R|\z)/s';
    private const ENTRY = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)\R(?<reason>[^\r\n]*)/m';

    public function __construct(private PhpUnitFailureLocator $locator) {}

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::SECTION, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $sut = $this->locator->locate($errorOutput)->sut;
        if (null === $sut || !class_exists($sut) || 1 !== preg_match(self::SECTION, $errorOutput, $section)) {
            return null;
        }
        $rc = new \ReflectionClass($sut);

        $lines = [];
        preg_match_all(self::ENTRY, $section['body'], $entries, PREG_SET_ORDER);
        foreach ($entries as $e) {
            preg_match_all('/\b[A-Za-z_]\w*\b/', $e['reason'], $words);
            foreach (array_unique($words[0]) as $word) {
                if ($rc->hasMethod($word) && $rc->getMethod($word)->isPublic()) {
                    $lines[] = sprintf('- `%s` vise `%s()`, méthode PUBLIQUE : `%s`', $e['test'], $word, $this->signature($rc->getMethod($word)));
                }
            }
        }
        if ([] === $lines) {
            return null;
        }

        return "### Tests ignorés sur des méthodes PUBLIQUES (markTestSkipped : interdit)\n"
            . "La raison « est privée » est fausse : ces méthodes sont appelables.\n"
            . implode("\n", $lines) . "\n"
            . "Correction : remplace `markTestSkipped(...)` par l'appel `\$this->sut->méthode(...)`, "
            . "déclare les variables qui manquent (une par paramètre requis, avec son type), "
            . "puis ajoute les assertions sur le résultat ou l'état observable. Ne supprime pas le test.\n";
    }

    private function signature(\ReflectionMethod $m): string
    {
        return sprintf(
            '%s(%s)%s',
            $m->getName(),
            implode(', ', array_map(
                static fn (\ReflectionParameter $p): string => trim(($p->getType() ?? '') . ' $' . $p->getName()),
                $m->getParameters(),
            )),
            $m->hasReturnType() ? ': ' . $m->getReturnType() : '',
        );
    }
}