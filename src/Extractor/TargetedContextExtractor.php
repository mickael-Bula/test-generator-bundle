<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Extractor;

final class TargetedContextExtractor
{
    private const APP_NAMESPACE = 'App\\'; // TODO : Quant est-il des classes du Bundle lui-même ? Et si PSR4 indique autre chose que App\\ ?
    private const MAX_BLUEPRINT_METHODS = 25;

    /** @var array<string, array<string,string>> */
    private array $usesCache = [];

    /**
     * @throws \ReflectionException
     */
    public function extractContext(string $filePath, string $fqcn): string
    {
        if (!class_exists($fqcn)) {
            return 'Classe introuvable pour extraction.';
        }

        $rc = new \ReflectionClass($fqcn);
        $source = (string) file_get_contents((string) $rc->getFileName());
        $ctor = $rc->getConstructor();

        // 1. Constructeur : dépendances (mocks) et valeurs réelles
        $mockable = $values = $dependencyClasses = [];
        foreach ($ctor?->getParameters() ?? [] as $p) {
            if (null !== $class = $this->mockableClass($p->getType())) {
                $mockable[] = '- ' . $this->param($p);
                $dependencyClasses[] = $class;
                continue;
            }
            $doc = $this->docLines($ctor, $p->getName());
            $values[] = '- ' . $this->param($p) . ([] !== $doc ? '   // ' . implode(' ', $doc) : '');
        }

        // 2. Méthodes publiques du SUT (hors héritage vendor)
        $own = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (!$m->isConstructor() && !$m->isDestructor() && !$this->isVendor($m)) {
                $own[] = '- public function ' . $this->describe($m);
            }
        }

        // 3. Méthodes des dépendances réellement appelées par le SUT
        $keysRead = $this->arrayKeysRead($source);
        $called = [];
        foreach ($dependencyClasses as $dep) {
            foreach ((new \ReflectionClass($dep))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isConstructor() || !str_contains($source, '->' . $m->getName() . '(')) {
                    continue;
                }

                $line = '- \\' . $dep . '::' . $this->describe($m);
                if (isset($keysRead[$m->getName()])) {
                    $line .= "\n    Clés lues par le SUT sur le retour : " . implode(', ', $keysRead[$m->getName()])
                        . ' (le mock doit toutes les fournir)';
                }
                $called[] = $line;
            }
        }

        // 4. Blueprints : toute classe de l'application citée dans les signatures ci-dessus
        $rendered = implode("\n", [...$values, ...$own, ...$called]);
        preg_match_all('/\\\\(' . preg_quote(self::APP_NAMESPACE, '/') . '[\w\\\\]+)/', $rendered, $m);
        $blueprints = [];
        foreach (array_unique($m[1]) as $class) {
            if ($class !== $fqcn && !\in_array($class, $dependencyClasses, true) && class_exists($class)) {
                $blueprints[] = $this->blueprint($class, $source);
            }
        }

        return implode("\n\n", array_filter([
            "=== CONTEXTE CIBLÉ DE LA CLASSE ===\nClasse : \\$fqcn\nFichier : $filePath",
            "Dépendances du constructeur (À MOCKER) :\n" . ([] !== $mockable ? implode("\n", $mockable) : 'Aucune'),
            [] !== $values
                ? "Autres arguments du constructeur (VRAIES valeurs à fournir, jamais de mock) :\n" . implode("\n", $values)
                : null,
            "Méthodes publiques de la classe :\n" . implode("\n", $own),
            [] !== $called
                ? "Méthodes appelées sur les dépendances (à configurer sur les mocks ; respecte ces types de retour) :\n" . implode("\n", $called)
                : null,
            [] !== $blueprints ? "=== OBJETS, DTOS ET ENUMS UTILISÉS ===\n" . implode("\n\n", $blueprints) : null,
        ]));
    }

    /**
     * @throws \ReflectionException
     */
    private function blueprint(string $fqcn, string $source): string
    {
        $rc = new \ReflectionClass($fqcn);

        if ($rc->isEnum()) {
            return sprintf('Enum \%s : %s', $fqcn, implode(', ', array_map(
                static fn (\ReflectionEnumUnitCase $c): string => '\\' . $fqcn . '::' . $c->getName(),
                (new \ReflectionEnum($fqcn))->getCases(),
            )));
        }

        $lines = [sprintf('Objet : \%s', $fqcn)];
        $lines[] = $rc->isInstantiable()
            ? sprintf('-> `new \%s(%s)`', $fqcn, implode(', ', array_map($this->param(...), $rc->getConstructor()?->getParameters() ?? [])))
            : '-> NON instanciable : ne pas utiliser `new`';

        $methods = array_filter(
            $rc->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => !$m->isConstructor() && $m->getDeclaringClass()->getName() === $fqcn,
        );

        $used = static fn (\ReflectionMethod $m): bool => str_contains($source, '->' . $m->getName() . '(');
        usort($methods, static fn (\ReflectionMethod $a, \ReflectionMethod $b): int => $used($b) <=> $used($a));

        foreach (\array_slice($methods, 0, self::MAX_BLUEPRINT_METHODS) as $m) {
            $lines[] = '-> public function ' . $this->describe($m);

            if ($used($m) && !str_starts_with($m->getName(), 'set') && null !== $body = MethodSource::ofComputed($m)) {
                $lines[] = "   Code réel (méthode calculée : pour changer son résultat, agis sur les données qu'elle lit) :\n"
                    . preg_replace('/^/m', '       ', $body);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Classe applicative mockable, ou null (type natif, enum, classe interne PHP, union...).
     */
    private function mockableClass(?\ReflectionType $t): ?string
    {
        if (!$t instanceof \ReflectionNamedType || $t->isBuiltin()) {
            return null;
        }
        $name = $t->getName();
        if (!class_exists($name) && !interface_exists($name)) {
            return null;
        }
        $r = new \ReflectionClass($name);

        return $r->isInternal() || $r->isEnum() ? null : $name;
    }

    private function describe(\ReflectionMethod $m): string
    {
        $sig = sprintf(
            '%s%s(%s): %s',
            $m->isStatic() ? 'static ' : '',
            $m->getName(),
            implode(', ', array_map($this->param(...), $m->getParameters())),
            $this->type($m->getReturnType()),
        );
        $doc = $this->docLines($m);

        return $sig . ([] !== $doc ? "\n    " . implode("\n    ", $doc) : '');
    }

    private function param(\ReflectionParameter $p): string
    {
        return sprintf(
            '%s %s$%s%s',
            $this->type($p->getType()),
            $p->isVariadic() ? '...' : '',
            $p->getName(),
            $p->isDefaultValueAvailable() ? ' = ' . $this->default($p) : '',
        );
    }

    /**
     * Caractère `\` uniquement devant les classes ; `?` conservé ; union et intersection gérées.
     */
    private function type(?\ReflectionType $t): string
    {
        return match (true) {
            $t instanceof \ReflectionNamedType => $this->named($t),
            $t instanceof \ReflectionUnionType => implode('|', array_map($this->type(...), $t->getTypes())),
            $t instanceof \ReflectionIntersectionType => implode('&', array_map($this->type(...), $t->getTypes())),
            default => 'mixed',
        };
    }

    private function named(\ReflectionNamedType $t): string
    {
        $name = $t->getName();
        if (!$t->isBuiltin() && !\in_array(strtolower($name), ['self', 'static'], true)) {
            $name = '\\' . $name;
        }

        return $t->allowsNull() && !\in_array($name, ['mixed', 'null'], true) ? '?' . $name : $name;
    }

    /**
     * @throws \ReflectionException
     */
    private function default(\ReflectionParameter $p): string
    {
        $v = $p->getDefaultValue();

        return match (true) {
            null === $v => 'null',
            [] === $v => '[]',
            \is_array($v) => '[…]',
            $v instanceof \UnitEnum => '\\' . $v::class . '::' . $v->name,
            \is_object($v) => 'new \\' . $v::class . '(…)',
            default => var_export($v, true),
        };
    }

    // Lignes `@param`/`@return` du PHPDoc, types qualifiés en FQCN (le LLM ne doit pas deviner les namespaces).
    /**
     * @return list<string>
     */
    private function docLines(\ReflectionFunctionAbstract $f, ?string $only = null): array
    {
        $doc = $f->getDocComment() ?: '';
        $type = '(?<t>(?:[^\s<{(]+|<[^>]*>|\{[^}]*\}|\([^)]*\))+)';
        $lines = [];

        if (preg_match_all('/@param\s+' . $type . '\s+\$(?<n>\w+)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (null === $only || $only === $x['n']) {
                    $lines[] = sprintf('@param %s $%s', $this->qualify($x['t'], $f), $x['n']);
                }
            }
        }
        if (null === $only && preg_match('/@return\s+' . $type . '/', $doc, $x)) {
            $lines[] = '@return ' . $this->qualify($x['t'], $f);
        }

        return $lines;
    }

    private function qualify(string $docType, \ReflectionFunctionAbstract $f): string
    {
        if (!$f instanceof \ReflectionMethod) {
            return $docType;
        }
        $rc = $f->getDeclaringClass();
        $uses = $this->usesOf($rc);

        return (string) preg_replace_callback(
            '/(?<![\\\\\w$])[A-Z]\w*/',
            static fn (array $m): string => '\\' . ($uses[$m[0]] ?? $rc->getNamespaceName() . '\\' . $m[0]),
            $docType,
        );
    }

    /** @return array<string,string> nom court => FQCN (hors `use Foo\{A, B}` groupés) */
    private function usesOf(\ReflectionClass $rc): array
    {
        if (isset($this->usesCache[$rc->getName()])) {
            return $this->usesCache[$rc->getName()];
        }

        preg_match_all(
            '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m',
            (string) file_get_contents((string) $rc->getFileName()),
            $u,
            PREG_SET_ORDER
        );

        $map = [];
        foreach ($u as $x) {
            $map[$x[2] ?? basename(str_replace('\\', '/', $x[1]))] = $x[1];
        }

        return $this->usesCache[$rc->getName()] = $map;
    }

    private function isVendor(\ReflectionMethod $m): bool
    {
        return str_contains((string) $m->getFileName(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, list<string>> méthode appelée => clés de tableau lues sur son retour
     */
    private function arrayKeysRead(string $source): array
    {
        $keys = [];
        preg_match_all('/\$(?<var>\w+)\s*=\s*\$this->\w+->(?<method>\w+)\(/', $source, $assigns, PREG_SET_ORDER);
        foreach ($assigns as $a) {
            preg_match_all('/\$' . $a['var'] . '\[\'(?<key>\w+)\'\]/', $source, $reads);
            foreach ($reads['key'] as $k) {
                $keys[$a['method']][$k] = true;
            }
        }

        return array_map('array_keys', $keys);
    }
}