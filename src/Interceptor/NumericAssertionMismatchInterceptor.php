<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final readonly class NumericAssertionMismatchInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/Failed asserting that (?<actual>[-+\d.eE]+) (?:matches expected|is identical to|is equal to) (?<expected>[-+\d.eE]+)\./';

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $m, PREG_SET_ORDER);

        $md = "### Valeur numérique attendue différente de la valeur obtenue\n";
        foreach ($m as $x) {
            $md .= sprintf("- Le SUT renvoie `%s`, le test attendait `%s`.\n", $x['actual'], $x['expected']);
        }

        return $md
            . "- Refais le calcul À PARTIR DES DONNÉES D'ENTRÉE du test, étape par étape, en lisant la formule dans le code du SUT.\n"
            . "- Écris l'attendu comme une expression (ex. `(10 * 100.0 + 5 * 105.0) / 15`), jamais comme un nombre calculé de tête.\n"
            . "- Ne recopie pas la valeur obtenue sans avoir vérifié le calcul : sinon le test valide un éventuel bug du SUT.\n";
    }
}
