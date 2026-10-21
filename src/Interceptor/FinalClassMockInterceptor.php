<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Psr\Cache\InvalidArgumentException;

/**
 * Lit les types des signatures publiques du SUT, via le localisateur.
 *
 * @noinspection PhpUnused
 */
final class FinalClassMockInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/Class\s+"(?<class>[^"]+)"\s+is declared "final" and cannot be doubled/i';

    public function __construct(private readonly PhpUnitFailureLocator $locator) {}

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    /**
     * @throws \ReflectionException|InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        if (0 === preg_match_all(self::PATTERN, $errorOutput, $m)) {
            return null;
        }

        $sut = $this->locator->locate($errorOutput)->sut;
        $sutTypes = $this->sutTypes($sut);

        $md = "### Classe `final` : `createMock()` est impossible\n"
            . (null !== $sut
                ? sprintf("- Classe testée (SUT) : `\\%s`\n", $sut)
                : "- Classe testée (SUT) : introuvable (`#[CoversClass]` absent ou classe non chargeable)\n");

        foreach (array_unique($m['class']) as $class) {
            $class = ltrim($class, '\\');
            $md .= sprintf(
                "- `\\%s` est déclarée `final` : PHPUnit ne peut pas en créer de double.\n  - %s\n",
                $class,
                $this->advice($class, $sutTypes),
            );
        }

        return $md . "Ne change ni les assertions ni la structure du test.\n";
    }

    /**
     * @param list<string> $sutTypes classes citées dans les signatures publiques du SUT
     * @throws \ReflectionException
     */
    private function advice(string $class, array $sutTypes): string
    {
        if (!class_exists($class)) {
            return "classe introuvable par l'autoload : vérifie son FQCN.";
        }
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $required = $constructor?->getNumberOfRequiredParameters() ?? 0;

        $interfaces = array_values(array_filter(
            $reflection->getInterfaceNames(),
            static fn (string $i): bool => !(new \ReflectionClass($i))->isInternal(),
        ));
        $viaInterface = array_values(array_intersect($interfaces, $sutTypes));
        $concrete = \in_array($class, $sutTypes, true);
        $names = static fn (array $list): string => implode(', ', array_map(static fn (string $i): string => "`\\$i`", $list));

        return match (true) {
            [] !== $viaInterface => sprintf(
                "le SUT déclare l'interface %s : mocke l'interface (`createMock(\\%s::class)`) et type la propriété avec elle.",
                $names($viaInterface),
                $viaInterface[0],
            ),
            $concrete && 0 === $required => "le SUT exige cette classe concrète (un mock d'interface ne conviendrait pas) "
                . "et son constructeur ne demande aucun argument : instancie-la avec `new \\" . $class . '()`.',
            $concrete => sprintf(
                "le SUT exige cette classe concrète : instancie-la avec `new`, "
                . "en fournissant à son constructeur `__construct(%s)` des mocks d'interfaces ou de vraies valeurs.",
                $this->parameters($constructor),
            ),
            [] !== $interfaces => sprintf(
                "elle implémente %s : si le SUT ne l'exige pas par son type, mocke plutôt l'interface.",
                $names($interfaces)
            ),
            0 === $required => 'instancie-la avec `new \\' . $class . '()` (son constructeur ne demande aucun argument).',
            default => sprintf(
                'instancie-la avec `new`, en fournissant à son constructeur `__construct(%s)` ses dépendances.',
                $this->parameters($constructor)
            ),
        };
    }

    private function parameters(?\ReflectionMethod $constructor): string
    {
        return implode(', ', array_map(
            static fn (\ReflectionParameter $p): string => trim(($p->getType() ?? '') . ' $' . $p->getName()),
            $constructor?->getParameters() ?? [],
        ));
    }

    /**
     * @return list<string> classes citées dans les paramètres des méthodes publiques du SUT (constructeur compris)
     */
    private function sutTypes(?string $sut): array
    {
        if (null === $sut || !class_exists($sut)) {
            return [];
        }

        $types = [];
        foreach ((new \ReflectionClass($sut))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                foreach ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : [$type] as $named) {
                    if ($named instanceof \ReflectionNamedType && !$named->isBuiltin()) {
                        $types[] = ltrim($named->getName(), '\\');
                    }
                }
            }
        }

        return array_values(array_unique($types));
    }
}