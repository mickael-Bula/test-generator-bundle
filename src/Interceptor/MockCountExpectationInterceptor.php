<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\MockCountAnalyzer;
use Mika\TestGeneratorBundle\Interceptor\Support\MockCountFinding;
use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;

/** @noinspection PhpUnused */
final readonly class MockCountExpectationInterceptor implements ErrorInterceptorInterface
{
    public function __construct(
        private MockCountAnalyzer $analyzer,
        private PhpUnitFailureLocator $locator,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'was not expected to be called') || str_contains($errorOutput, 'Method was expected to be called');
    }

    public function intercept(string $errorOutput): ?string
    {
        $file = $this->locator->locate($errorOutput)->testFile;
        $findings = null !== $file && is_file($file) ? $this->analyzer->analyze($errorOutput, (string) file_get_contents($file)) : [];

        $md = "### Nombre d'appels d'un mock différent de l'attendu\n";
        foreach ($findings as $f) {
            $md .= $this->describe($f);
        }

        return $md . "\nRègles :\n"
            . "- Écris l'attendu avec `exactly(N)`, N = itérations de la boucle du SUT × appels par itération, calculé sur les données de CE test.\n"
            . "- N'utilise PAS `any()` ni `atLeastOnce()` et ne supprime pas l'attente pour faire passer le test : la vérification disparaîtrait.\n"
            . "- Une attente `expects()` se déclare AVANT l'appel du SUT : PHPUnit ne compte que les appels faits après.\n"
            . "- Si le code du SUT ne fait pas ce que le nom du test annonce, adapte les DONNÉES du test (taille de la collection, valeurs qui franchissent la condition).\n";
    }

    private function describe(MockCountFinding $f): string
    {
        $md = sprintf("\n- `%s` : mock `%s()`", $f->test, $f->method);
        $md .= 'too-many' === $f->kind
            ? ', appelé plus souvent que l\'attente' . (null !== $f->limit ? sprintf(' (%d)', $f->limit) : '')
            : sprintf(', attendu %s fois, appelé %d fois', null !== $f->limit ? (string) $f->limit : 'N', (int) $f->observed);

        $md .= null !== $f->derived
            ? sprintf("\n  - Le code du SUT donne %d appel(s) pour ce test (%s) : écris `exactly(%d)`.", $f->derived, $f->derivation, $f->derived)
            : sprintf("\n  - Nombre non déductible automatiquement (%s) : lis la méthode du SUT et compte.", $f->derivation);

        if (0 === $f->observed) {
            $md .= null !== $f->moveBefore
                ? "\n  - L'attente est déclarée APRÈS l'appel du SUT : déplace-la AVANT le `// QUAND`."
                : "\n  - Le SUT n'a jamais appelé cette méthode : vérifie que les données du test atteignent l'appel (condition, collection vide).";
        }

        return $md;
    }
}