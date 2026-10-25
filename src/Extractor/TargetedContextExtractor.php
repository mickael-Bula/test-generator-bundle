<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Extractor;

use Mika\TestGeneratorBundle\Reflection\arrayKeyReads;
use Mika\TestGeneratorBundle\Reflection\DocTypeResolver;

final class TargetedContextExtractor
{
    private const APP_NAMESPACE = 'App\\'; // TODO : Quant est-il des classes du Bundle lui-même ? Et si PSR4 indique autre chose que App\\ ?
    private const MAX_BLUEPRINT_METHODS = 25;

    public function __construct(private readonly DocTypeResolver $docTypes) {}

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
            $doc = $this->docTypes->lines($ctor, $p->getName());
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
        $keysRead = ArrayKeyReads::of($source);
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
                $blueprints[$class] = $this->blueprint($class, $source);
            }
        }

        // Enums importés par le SUT : cités dans son code même s'ils n'apparaissent dans aucune signature
        preg_match_all('/^use\s+([\w\\\\]+)\s*;/m', $source, $imports);
        foreach (array_unique($imports[1]) as $imported) {
            if (enum_exists($imported)) {
                $blueprints[$imported] = $this->blueprint($imported, $source);
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

        // On extrait les cas des Enums
        if ($rc->isEnum()) {
            $enum = new \ReflectionEnum($fqcn);
            $cases = [];
            foreach ($enum->getCases() as $case) {
                /** @var \ReflectionEnumUnitCase|\ReflectionEnumBackedCase $case */
                $cases[] = '\\' . $fqcn . '::' . $case->getName()
                    . ($case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '');
            }

            return sprintf(
                'Enum \%s%s : %s',
                $fqcn,
                $enum->isBacked() ? ' (backed par ' . $enum->getBackingType() . ')' : '',
                implode(', ', $cases),
            );
        }

        $lines = [sprintf('Objet : \%s', $fqcn)];
        $lines[] = $rc->isInstantiable()
            ? sprintf('-> `new \%s(%s)`', $fqcn, implode(', ', array_map($this->param(...), $rc->getConstructor()?->getParameters() ?? [])))
            : '-> NON instanciable : ne pas utiliser `new`';

        $methods = array_filter(
            $rc->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m) : bool => !$m->isConstructor() && $m->getDeclaringClass()->getName() === $fqcn,
        );

        $used = static function (\ReflectionMethod $m) use ($source): bool {
            $name = $m->getName();

            return str_contains($source, '->' . $name . '(')
                || (str_starts_with($name, 'set') && str_contains($source, '->get' . substr($name, 3) . '('));
        };
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
        $doc = $this->docTypes->lines($m);

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

    private function isVendor(\ReflectionMethod $m): bool
    {
        return str_contains((string) $m->getFileName(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }
}
