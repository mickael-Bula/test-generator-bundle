<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Extractor\MethodSource;
use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Reflection\EnumCases;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use Psr\Cache\InvalidArgumentException;

/**
 * @noinspection PhpUnused
 */
final readonly class EnumCaseInterceptor implements ErrorInterceptorInterface
{
    // language=RegExp
    private const UNDEFINED = '/(?:Error: |Message was: ")?(?:Undefined constant|Access to undefined constant) (?<class>[\w\\\\]+)::(?<case>\w+)/';
    // language=RegExp
    private const VALUE = '/ValueError: "(?<value>[^"]*)" is not a valid backing value for enum "?(?<enum>[\w\\\\]+)"?/';
    private const TEST_LINE = '#(?<file>\S+Test\.php):(?<line>\d+)#';
    private const SRC_LINE = '#(?<file>\S+/src/\S+\.php):(?<line>\d+)#';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private TestSnapshotStore $snapshots,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function supports(string $errorOutput): bool
    {
        return [] !== $this->blocks($errorOutput);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        $blocks = $this->blocks($errorOutput);

        return [] === $blocks ? null
            : "### Cas d'enum inexistant ou valeur de enum invalide\n" . implode('', $blocks)
            . "\nRègles :\n"
            . "- Utilise EXACTEMENT les cas listés (écris-les `\\Namespace\\Enum::CAS`) : n'en invente aucun.\n"
            . "- Si le scénario voulu n'existe pas dans l'enum, adapte les données du test ; ne crée pas de cas.\n";
    }

    /**
     * @return list<string> un bloc par cas ou valeur fautif ; vide si rien ne concerne un enum
     * @throws InvalidArgumentException
     */
    private function blocks(string $output): array
    {
        if (!str_contains($output, 'Undefined constant') && !str_contains($output, 'not a valid backing value')) {
            return [];
        }

        $sut = $this->locator->locate($output)->sut;
        $reflection = null !== $sut && class_exists($sut) ? new \ReflectionClass($sut) : null;
        $blocks = [];
        $seen = [];

        preg_match_all(self::UNDEFINED, $output, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $e) {
            $enum = ltrim($e['class'][0], '\\');
            $catalog = EnumCases::of($enum);
            if (null === $catalog || isset($seen[$enum . '::' . $e['case'][0]])) {
                continue; // pas un enum (constante de classe ordinaire), ou déjà décrit
            }
            $seen[$enum . '::' . $e['case'][0]] = true;
            $blocks[] = $this->undefined(
                $enum,
                $e['case'][0],
                $catalog,
                $this->lineAfter($output, $e[0][1], self::TEST_LINE),
                $this->lineAt($output, $e[0][1]),
            );
        }

        preg_match_all(self::VALUE, $output, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $e) {
            $enum = ltrim($e['enum'][0], '\\');
            $catalog = EnumCases::of($enum);
            if (null === $catalog || isset($seen[$enum . '=' . $e['value'][0]])) {
                continue;
            }
            $seen[$enum . '=' . $e['value'][0]] = true;
            $blocks[] = $this->invalidValue(
                $enum, $e['value'][0], $catalog, $reflection,
                $this->lineAfter($output, $e[0][1], self::SRC_LINE),
                $this->lineAfter($output, $e[0][1], self::TEST_LINE),
            );
        }

        return $blocks;
    }

    /**
     * @param array{backing: ?string, cases: array<string, string|int|null>} $catalog
     */
    private function undefined(string $enum, string $case, array $catalog, ?string $testLine, string $context): string
    {
        $exact = EnumCases::exact($case, $catalog['cases']);
        $close = EnumCases::closest($case, $catalog['cases']);

        $md = sprintf("\n- `\\%s::%s` n'existe pas.\n", $enum, $case);
        $md .= sprintf(
            "  - Cas de `\\%s`%s : %s\n",
            $enum,
            null !== $catalog['backing'] ? sprintf(' (backed par %s)', $catalog['backing']) : '',
            $this->list($catalog['cases']),
        );
        $md .= match (true) {
            null !== $exact => sprintf("  - Même cas, écrit autrement : `\\%s::%s`.\n", $enum, $exact),
            [] !== $close => sprintf(
                "  - Cas proches : %s (à n'utiliser que s'ils correspondent au scénario).\n",
                implode(', ', array_map(static fn (string $c): string => "`$c`", $close))
            ),
            default => '',
        };

        // Pour un test d’exception, l’indice doit dire que le test attendait une exception qui n’est jamais atteinte
        if (str_contains($context, 'matches expected exception')) {
            $md .= "  - Le test attend une exception, mais ce cas inexistant lève une `Error` AVANT l'appel du SUT. "
                . "Corrige d'abord le cas ; si l'enum est le type d'un paramètre, "
                . "un cas invalide n'atteindra jamais le SUT (PHP le refuse) : supprime alors ce scénario.\n";
        }

        return $md . (null !== $testLine ? sprintf("  - Ligne du test : `%s`\n", $testLine) : '');
    }

    /**
     * @param array{backing: ?string, cases: array<string, string|int|null>} $catalog
     */
    private function invalidValue(
        string $enum,
        string $value,
        array $catalog,
        ?\ReflectionClass $sut,
        ?string $srcLine,
        ?string $testLine
    ): string {
        $md = sprintf("\n- `\\%s::from('%s')` : aucune valeur de ce nom (ValueError).\n", $enum, $value);
        $md .= sprintf("  - Valeurs valides : %s\n", $this->list($catalog['cases']));
        $md .= null !== $srcLine ? sprintf("  - Conversion dans le SUT : `%s`\n", $srcLine) : '';
        $md .= null !== $testLine ? sprintf("  - Ligne du test : `%s`\n", $testLine) : '';

        $short = basename(str_replace('\\', '/', $enum));
        $typed = [];
        $converting = [];
        foreach ($sut?->getMethods(\ReflectionMethod::IS_PUBLIC) ?? [] as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && ltrim($type->getName(), '\\') === $enum) {
                    $typed[] = $method->getName() . '()';
                }
            }
            $source = MethodSource::full($method, 400) ?? '';
            if (str_contains($source, $short . '::from(') || str_contains($source, $short . '::tryFrom(')) {
                $converting[] = $method->getName() . '()';
            }
        }

        return $md . match (true) {
                [] !== $typed => sprintf(
                    "  - Ces méthodes du SUT prennent directement l'enum (%s) : une valeur invalide est IMPOSSIBLE (PHP la refuse avant l'appel). "
                    . "Supprime ce scénario.\n",
                    implode(', ', array_unique($typed))
                ),
                [] !== $converting => sprintf(
                    "  - Ces méthodes du SUT convertissent une valeur avec `from()` / `tryFrom()` : %s. Pour tester une valeur invalide : "
                    . "`\$this->expectException(\\ValueError::class)` AVANT l'appel si le SUT utilise `from()` ; `tryFrom()` retourne null (vérifie ce que le SUT en fait).\n",
                    implode(', ', array_unique($converting)),
                ),
                default => "  - Passe une valeur valide ; si l'invalidité est le comportement testé, déclare `\$this->expectException(\\ValueError::class)` AVANT l'action.\n",
            };
    }

    /**
     * @param array<string, string|int|null> $cases
     */
    private function list(array $cases): string
    {
        $items = [];
        foreach (\array_slice($cases, 0, 25, true) as $name => $value) {
            $items[] = sprintf('`%s`%s', $name, null === $value ? '' : ' = ' . var_export($value, true));
        }

        return implode(', ', $items) . (\count($cases) > 25 ? sprintf(' … (%d cas au total)', \count($cases)) : '');
    }

    /**
     * Première ligne de code citée par la trace après $offset (test : instantané si le fichier a été supprimé).
     */
    private function lineAfter(string $output, int $offset, string $pattern): ?string
    {
        // Rapport PHPStan (« Ligne N : … ») : pas de trace à lire
        $lineStart = (int) strrpos(substr($output, 0, $offset), "\n");
        if (str_contains(substr($output, $lineStart, $offset - $lineStart), 'Ligne ')
            || 1 !== preg_match($pattern, $output, $x, 0, $offset)
        ) {
            return null;
        }

        $file = is_file($x['file']) ? $x['file'] : (str_contains($x['file'], 'Test.php') ? $this->snapshots->find($x['file']) : null);
        $lines = null === $file ? [] : (file($file, FILE_IGNORE_NEW_LINES) ?: []);

        return isset($lines[(int) $x['line'] - 1]) ? trim($lines[(int) $x['line'] - 1]) : null;
    }

    /**
     * La ligne du rapport qui contient l'offset : elle porte le message d'erreur complet.
     */
    private function lineAt(string $output, int $offset): string
    {
        $start = strrpos(substr($output, 0, $offset), "\n");
        $start = false === $start ? 0 : $start + 1;
        $end = strpos($output, "\n", $offset);

        return substr($output, $start, (false === $end ? \strlen($output) : $end) - $start);
    }
}