<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use Psr\Cache\InvalidArgumentException;

/**
 * Intercepteur indiquant la manière de gérer plusieurs appels d'une même méthode de mock avec des paramètres différents (ex : ->with()).
 *
 * @noinspection PhpUnused
 */
final readonly class MockParameterMismatchInterceptor implements ErrorInterceptorInterface
{
    // language=RegExp
    private const ENTRY = '/Expectation failed for method name is "(?<method>\w+)" when invoked (?<times>\d+) times?\.?\RParameter (?<index>\d+) for invocation (?<class>[\w\\\\]+)::\w+\(/';
    // language=RegExp
    private const SOURCE_LINE = '#(?<file>\S+/src/\S+\.php):(?<line>\d+)#';
    private const MAX_PLACEHOLDERS = 6;

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private Parser $parser,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::ENTRY, $errorOutput);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        $sut = $this->locator->locate($errorOutput)->sut;

        $md = '';
        $single = false;

        /** @var array<string, int> $multi méthode => nombre d'appels attendus (2 ou plus) */
        $multi = [];
        /** @var array<string, int> $merged méthode => nombre d'appels du SUT, quand le test en attend moins */
        $merged = [];

        foreach (preg_split('/^\d+\) /m', $errorOutput) ?: [] as $chunk) {
            if (1 !== preg_match(self::ENTRY, $chunk, $m)) {
                continue;
            }
            $test = 1 === preg_match('/^[\w\\\\]+::(?<t>\w+)/', $chunk, $t) ? $t['t'] : '(test inconnu)';
            $times = (int) $m['times'];

            $md .= sprintf(
                "\n- `%s` : `with(...)` de `%s()` ne correspond pas à l'argument n°%d réellement passé par le SUT (%s appel(s) attendu(s)).\n",
                $test, $m['method'], (int) $m['index'] + 1, $m['times'],
            );

            // Écart : lignes « - attendu » / « + obtenu » du diff de PHPUnit
            if (1 === preg_match('/\+\+\+ Actual\R(?<d>.*?)(?:\R\R|\R--\R|\z)/s', $chunk, $dm)) {
                preg_match_all('/^(?<s>[-+])[ \t]+(?<l>\S.*)$/m', $dm['d'], $diff, PREG_SET_ORDER);
                foreach ($diff as $d) {
                    $md .= sprintf("  - %s : `%s`\n", '-' === $d['s'] ? 'attendu par le test' : 'passé par le SUT ', trim($d['l']));
                }
            }

            $line = $this->sutLine($sut, $chunk);
            $calls = \count($this->callSites($sut, $m['method'], $line));

            if ($times >= 2 || $calls >= 2) {
                $multi[$m['method']] = max($times, $calls);
                if ($calls > $times) {
                    $merged[$m['method']] = $calls;
                }
                // Appelée plusieurs fois : le LLM doit voir TOUS les appels du SUT, pas seulement celui qui échoue
                $md .= $this->allSites($sut, $m['method'], $line) ?: ($this->faultySite($chunk) ?? '');
            } else {
                $single = true;
                // La trace donne l'appel exact ; à défaut, tous les appels de la méthode dans le SUT
                $md .= $this->faultySite($chunk) ?? $this->allSites($sut, $m['method'], $line);
            }
        }

        return '' === $md ? null : "### Arguments d'un mock différents de `with(...)`\n" . $md
            . "\nCorrection :\n" . self::correction($single, $multi, $merged);
    }

    /**
     * Ligne du SUT désignée par la trace, seulement quand elle est dans le fichier de la classe testée.
     */
    private function sutLine(?string $sut, string $chunk): ?int
    {
        if (null === $sut || !class_exists($sut) || 1 !== preg_match(self::SOURCE_LINE, $chunk, $s)) {
            return null;
        }
        $sutFile = (new \ReflectionClass($sut))->getFileName();
        $traced = realpath($s['file']);

        return false !== $sutFile && false !== $traced && realpath($sutFile) === $traced ? (int) $s['line'] : null;
    }

    private function faultySite(string $chunk): ?string
    {
        if (1 !== preg_match(self::SOURCE_LINE, $chunk, $s) || !is_file($s['file'])) {
            return null;
        }
        $line = (file($s['file'], FILE_IGNORE_NEW_LINES) ?: [])[(int) $s['line'] - 1] ?? null;

        return null === $line ? null : sprintf("  - Appel fautif dans le SUT (ligne %d) : `%s`\n", (int) $s['line'], trim($line));
    }

    private function allSites(?string $sut, string $method, ?int $line = null): string
    {
        $out = '';
        foreach (\array_slice($this->callSites($sut, $method, $line), 0, 4) as $site) {
            $out .= "  - Appel dans le SUT, $site\n";
        }

        return $out;
    }

    /**
     * Appels de $method dans le SUT : dans la méthode qui contient $line si elle est connue, sinon dans tout le fichier.
     *
     * @return list<string>
     */
    private function callSites(?string $sut, string $method, ?int $line = null): array
    {
        if (null === $sut || !class_exists($sut) || false === $file = (new \ReflectionClass($sut))->getFileName()) {
            return [];
        }
        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }

        $finder = new NodeFinder();
        $scope = $stmts;
        if (null !== $line) {
            $enclosing = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod
                && $n->getStartLine() <= $line && $line <= $n->getEndLine());
            if ($enclosing instanceof Node\Stmt\ClassMethod) {
                $scope = [$enclosing];
            }
        }

        $printer = new Standard();
        $sites = [];
        foreach ($finder->findInstanceOf($scope, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && 0 === strcasecmp($call->name->toString(), $method)) {
                $text = (string) preg_replace('/\s+/', ' ', $printer->prettyPrintExpr($call));
                $sites[] = sprintf('ligne %d : `%s`', $call->getStartLine(), mb_strimwidth($text, 0, 200, '…'));
            }
        }

        return $sites;
    }

    /**
     * Sans méthode attendue plusieurs fois, le texte d'origine est conservé tel quel.
     *
     * @param array<string, int> $multi  méthode => nombre d'appels attendus (2 ou plus)
     * @param array<string, int> $merged méthode => nombre d'appels du SUT, quand le test en attend moins
     */
    public static function correction(bool $single, array $multi, array $merged = []): string
    {
        $align = "- Le SUT fait foi : aligne `with(...)` sur les arguments qu'il passe, "
            . "ou adapte les DONNÉES si l'argument dépend d'une condition du SUT.\n";

        if ([] === $multi) {
            return $align
                . "- Si la méthode est appelée PLUSIEURS fois avec des arguments différents, "
                . "un seul `with()` ne peut pas convenir (`withConsecutive()` n'existe plus depuis PHPUnit 10) : "
                . "utilise `->willReturnCallback(function (...\$args) { ... })` qui répond selon les arguments reçus, "
                . "ou ajuste le nombre d'appels attendus.\n";
        }

        $method = (string) array_key_first($multi);
        $closing = isset($merged[$method])
            ? "\nLe SUT contient " . $merged[$method] . ' appels de `' . $method . '()` dans cette méthode, et le test déclare moins d\'appels '
            . "ou des attentes séparées. Si ces appels s'exécutent tous avec les données du test (aucune condition ne les écarte), "
            . "remplace TOUTES les attentes `->method('" . $method . "')` du test par cette UNIQUE attente `exactly(" . $merged[$method] . ")`. "
            . "Ne modifie pas le reste du test.\n"
            : "\nNe modifie ni le nombre d'appels attendus ni le reste du test : "
            . "corrige seulement la façon dont les arguments de ces appels sont vérifiés.\n";

        return ($single ? $align . "\nPour les méthodes attendues plusieurs fois :\n" : '')
            . "- Un seul `with(...)` impose les MÊMES arguments à TOUS les appels : "
            . "alterner la valeur dans `with()` d'une tentative à l'autre ne peut pas aboutir, l'un des appels échouera toujours.\n"
            . "- `withConsecutive()` et `at()` n'existent plus depuis PHPUnit 10 : ne les utilise pas.\n"
            . "- Relève, dans l'ordre d'exécution du code testé, les arguments réels de CHAQUE appel, "
            . "puis enregistre ceux que le mock reçoit et vérifie-les dans la partie ALORS :\n\n"
            . self::recordingPattern($method, $multi[$method])
            . $closing
            . (\count($multi) > 1 ? "Applique le même schéma aux autres méthodes attendues plusieurs fois.\n" : '');
    }

    private static function recordingPattern(string $method, int $times): string
    {
        $count = min($times, self::MAX_PLACEHOLDERS);
        $returns = $arguments = [];
        for ($i = 1; $i <= $count; ++$i) {
            $returns[] = sprintf("<valeur renvoyée par l'appel %d>", $i);
            $arguments[] = sprintf("[<arguments de l'appel %d>]", $i);
        }

        $lines = [
            '$received = [];',
            sprintf('$returns = [%s];', implode(', ', $returns)),
            sprintf('$mock->expects(self::exactly(%d))->method(\'%s\')->willReturnCallback(', $times, $method),
            '    static function (mixed ...$arguments) use (&$received, &$returns): mixed {',
            '        $received[] = $arguments;',
            '',
            '        return array_shift($returns);',
            '    },',
            ');',
            '',
            sprintf('self::assertEquals([%s], $received);', implode(', ', $arguments)),
        ];

        $out = '';
        foreach ($lines as $line) {
            $out .= '' === $line ? "\n" : '      ' . $line . "\n";
        }

        return $out;
    }
}
