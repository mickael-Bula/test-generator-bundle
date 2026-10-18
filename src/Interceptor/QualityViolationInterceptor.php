<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Reflection\SutCallGraph;

/** @noinspection PhpUnused */
final readonly class QualityViolationInterceptor implements ErrorInterceptorInterface
{
    private const MARKER = 'ERREUR DE QUALITÉ (ANALYSE STATIQUE DU TEST)';
    private const ENTRY = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)\R\[(?<rule>[\w-]+)\]\s+(?<msg>[^\r\n]+)/m';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private SutCallGraph $callGraph,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, self::MARKER);
    }

    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::ENTRY, substr($errorOutput, (int) strpos($errorOutput, self::MARKER)), $entries, PREG_SET_ORDER);
        if ([] === $entries) {
            return null;
        }

        $sut = $this->locator->locate($errorOutput)->sut;
        $reflection = null !== $sut && class_exists($sut) ? new \ReflectionClass($sut) : null;

        $md = "### Test rejeté par l'analyse statique (il peut passer PHPUnit mais ne prouve rien)\n";
        foreach ($entries as $e) {
            $md .= sprintf("- `%s` : %s\n", $e['test'], $e['msg']);

            $callers = [];
            if ('private-call' === $e['rule'] && null !== $reflection && 1 === preg_match('/::(\w+)\(\)/', $e['msg'], $m)) {
                $callers = $this->callGraph->publicCallersOf($reflection, $m[1]);
            }

            $md .= match ($e['rule']) {
                'private-call' => "  - Une méthode non publique ne se teste qu'à travers les publiques : SUPPRIME ce test, ne le réécris pas avec Reflection.\n"
                    . ([] !== $callers
                        ? '  - Méthodes publiques qui l\'atteignent : ' . implode(', ', array_map(static fn (string $c): string => "`$c()`", $callers))
                        . ". Couvre sa logique par un scénario de l'une d'elles, avec des données (retours de mocks, valeurs d'entrée) qui franchissent ses branches.\n"
                        : ''),
                'trivial-assertion' => "  - Supprime cette assertion : le test a d'autres vérifications.\n",
                'trivial-only' => "  - Remplace-la par une vraie vérification : valeur retournée, état d'un objet modifié par le SUT "
                    . "(`assertSame(expression, \$objet->getX())`) ou appel de mock (`expects(self::exactly(n))` déclaré AVANT l'appel). "
                    . "`assertTrue(true)` et `assertInstanceOf` sur un objet créé par `new` ne vérifient rien.\n",
                default => "  - La classe ne contient aucun test exécutable : régénère-la à partir des méthodes PUBLIQUES.\n",
            };
        }

        return $md;
    }
}