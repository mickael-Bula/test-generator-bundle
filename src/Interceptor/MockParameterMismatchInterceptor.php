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

/** @noinspection PhpUnused */
final readonly class MockParameterMismatchInterceptor implements ErrorInterceptorInterface
{
    private const ENTRY = '/Expectation failed for method name is "(?<method>\w+)" when invoked (?<times>\d+) times?\.?\RParameter (?<index>\d+) for invocation (?<class>[\w\\\\]+)::\w+\(/';
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
        foreach (preg_split('/^\d+\) /m', $errorOutput) ?: [] as $chunk) {
            if (1 !== preg_match(self::ENTRY, $chunk, $m)) {
                continue;
            }
            $test = 1 === preg_match('/^[\w\\\\]+::(?<t>\w+)/', $chunk, $t) ? $t['t'] : '(test inconnu)';

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
            $exact = null;
            if (1 === preg_match('#(?<file>\S+/src/\S+\.php):(?<line>\d+)#', $chunk, $s) && is_file($s['file'])) {
                $source = file($s['file'], FILE_IGNORE_NEW_LINES) ?: [];
                $exact = $source[(int) $s['line'] - 1] ?? null;
            }
            if (null !== $exact) {
                $md .= sprintf("  - Appel fautif dans le SUT (ligne %d) : `%s`\n", (int) $s['line'], trim($exact));
            } else {
                foreach ($this->callSites($sut, $m['method']) as $site) {
                    $md .= "  - Appel dans le SUT, $site\n";
                }
            }
        }

        return "### Arguments d'un mock différents de `with(...)`\n" . $md
            . "\nCorrection :\n"
            . "- Le SUT fait foi : aligne `with(...)` sur les arguments qu'il passe, ou adapte les DONNÉES si l'argument dépend d'une condition du SUT.\n"
            . "- Si la méthode est appelée PLUSIEURS fois avec des arguments différents, un seul `with()` ne peut pas convenir (`withConsecutive()` n'existe plus depuis PHPUnit 10) : "
            . "utilise `->willReturnCallback(function (...\$args) { ... })` qui répond selon les arguments reçus, ou ajuste le nombre d'appels attendus.\n";
    }

    /**
     * @return list<string>
     */
    private function callSites(?string $sut, string $method): array
    {
        if (null === $sut || !class_exists($sut) || false === $file = (new \ReflectionClass($sut))->getFileName()) {
            return [];
        }
        try {
            $stmts = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error) {
            return [];
        }

        $printer = new Standard();
        $sites = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && 0 === strcasecmp($call->name->toString(), $method)) {
                $text = (string) preg_replace('/\s+/', ' ', $printer->prettyPrintExpr($call));
                $sites[] = sprintf('ligne %d : `%s`', $call->getStartLine(), mb_strimwidth($text, 0, 200, '…'));
            }
        }

        return \array_slice($sites, 0, 4);
    }
}