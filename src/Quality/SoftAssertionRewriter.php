<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/**
 * PHPUnit s'arrête à la première assertion fausse d'un test : le fixer découvre alors les autres une par tour.
 * Ce service réécrit les assertions de premier niveau des tests demandés pour que chacune soit exécutée dans un try/catch,
 * et lit ensuite la liste des échecs que le test annonce à la fin.
 */
final readonly class SoftAssertionRewriter
{
    private const MARKER = 'MIKA_SOFT';
    // language=RegExp
    private const FAILURE = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)(?!\w| with data set)[^\r\n]*\R+' . self::MARKER . ' (?<json>\[.*\])\s*$/m';

    public function __construct(private Parser $parser)
    {
    }

    /**
     * @param list<string> $tests
     *
     * @return array{code: string, sources: array<string, array<int, string>>}|null ligne d'origine => texte de l'assertion, par test
     */
    public function rewrite(string $code, array $tests): ?array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return null;
        }

        $finder = new NodeFinder();
        $inserts = []; // [position, rang, texte] : à une même position, le rang le plus petit passe devant
        $sources = [];
        foreach ($tests as $test) {
            $method = $finder->findFirst($stmts, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === $test);
            $body = $method instanceof Node\Stmt\ClassMethod ? ($method->stmts ?? []) : [];
            $assertions = array_values(array_filter($body, fn (Node\Stmt $s): bool => $this->isAssertion($s)));
            if ([] === $assertions) {
                continue;
            }

            $inserts[] = [$body[0]->getStartFilePos(), 0, '$__soft = []; '];
            foreach ($assertions as $statement) {
                $start = $statement->getStartFilePos();
                $end = $statement->getEndFilePos();
                $line = $statement->getStartLine();

                $sources[$test][$line] = trim((string) preg_replace('/\s+/', ' ', substr($code, $start, $end - $start + 1)));
                $inserts[] = [$start, 1, 'try { '];
                $inserts[] = [$end + 1, 0, sprintf(
                    ' } catch (\PHPUnit\Framework\ExpectationFailedException $__e) { $__soft[] = [%d, $__e->getMessage(), (string) $__e->getComparisonFailure()?->getDiff()]; }',
                    $line,
                )];
            }
            $inserts[] = [$body[array_key_last($body)]->getEndFilePos() + 1, 1, sprintf(
                " if ([] !== \$__soft) { self::fail('%s ' . json_encode(\$__soft, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); }",
                self::MARKER,
            )];
        }
        if ([] === $inserts) {
            return null;
        }

        // de la fin vers le début : les positions restent valides ; à position égale, le rang le plus grand est inséré en premier
        usort($inserts, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        foreach ($inserts as [$position, , $text]) {
            $code = substr_replace($code, $text, $position, 0);
        }

        return ['code' => $code, 'sources' => $sources];
    }

    /**
     * @return array<string, list<array{0: int, 1: string, 2: string}>> test => [ligne, message, diff]
     */
    public static function parseOutput(string $output): array
    {
        $found = [];
        preg_match_all(self::FAILURE, str_replace(["\r\n", "\r"], "\n", $output), $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $entries = json_decode($m['json'], true);
            if (!\is_array($entries)) {
                continue;
            }
            $found[$m['test']] = array_values(array_filter(
                $entries,
                static fn (mixed $e): bool => \is_array($e) && 3 === \count($e) && \is_int($e[0]) && \is_string($e[1]) && \is_string($e[2]),
            ));
        }

        return $found;
    }

    /**
     * @param list<array{line: int, source: string, message: string, diff: string}> $items
     */
    public static function render(array $items): string
    {
        $md = "TOUTES LES ASSERTIONS EN ÉCHEC DE CE TEST (PHPUnit s'arrête à la première ; "
            . "celles-ci viennent d'une exécution du test entier) :\n";
        foreach ($items as $item) {
            $detail = $item['message'];
            // un diff n'apporte rien quand le message donne déjà l'obtenu et l'attendu
            if ('' !== $item['diff'] && 0 === preg_match('/ (?:is identical to|matches expected|is equal to) /', $detail)) {
                $detail .= "\n" . $item['diff'];
            }
            $md .= sprintf("- ligne %d : `%s`\n", $item['line'], $item['source']);
            foreach (explode("\n", rtrim($detail)) as $line) {
                $md .= '    ' . $line . "\n";
            }
        }

        return $md . "Corrige-les TOUTES dans la même réponse. "
            . "Pour chacune, vérifie la valeur obtenue dans le code de la classe testée avant de l'écrire : "
            . "ne recopie pas une valeur sans avoir vérifié la formule.\n";
    }

    private function isAssertion(Node\Stmt $statement): bool
    {
        $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;

        return ($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall)
            && $call->name instanceof Node\Identifier
            && str_starts_with($call->name->toString(), 'assert');
    }
}
