<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\CollectionTypes;
use Mika\TestGeneratorBundle\Reflection\DocTypeResolver;

/** @noinspection PhpUnused */
final class CollectionOrArrayTypeErrorInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/TypeError:\s+(?<class>[\w\\\\]+)::(?<method>\w+)\(\):\s+Argument\s+#(?<pos>\d+)\s+\(\$(?<arg>\w+)\)\s+must be of type\s+(?<expected>[^,\r\n]+),\s+(?<given>\w+)\s+given/';
    private const ARRAY_COLLECTION = 'Doctrine\Common\Collections\ArrayCollection';

    public function __construct(private readonly DocTypeResolver $docTypes) {}

    public function supports(string $errorOutput): bool
    {
        return [] !== $this->errors($errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $blocks = array_map($this->describe(...), $this->errors($errorOutput));

        return [] === $blocks ? null : implode("\n", $blocks);
    }

    /** Seules les erreurs dont le type ATTENDU est une collection ; `null given` revient à NullArgumentTypeErrorInterceptor. */
    private function errors(string $output): array
    {
        preg_match_all(self::PATTERN, $output, $m, PREG_SET_ORDER);

        return array_values(array_filter(
            $m,
            static fn (array $e): bool => 'null' !== strtolower($e['given']) && CollectionTypes::isCollectionLike($e['expected']),
        ));
    }

    private function describe(array $e): string
    {
        $expected = trim($e['expected']);
        $valueType = $this->valueType($e['class'], $e['method'], $e['arg']);

        return sprintf("### TypeError (collection) : `%s::%s()`, argument #%s `\$%s`\n", $e['class'], $e['method'], $e['pos'], $e['arg'])
            . sprintf("- Reçu : `%s` ; attendu : `%s`\n", $e['given'], $expected)
            . (null !== $valueType ? "- Type des éléments (PHPDoc) : `$valueType`\n" : '')
            . '- Correction : ' . $this->advice($expected, $valueType) . "\n";
    }

    private function advice(string $expected, ?string $valueType): string
    {
        $type = CollectionTypes::main($expected);
        $wrap = match (true) {
            'array' === strtolower($type) => 'un tableau littéral `[...]` (un `ArrayIterator` ou une collection ne conviennent pas à `array`)',
            'iterable' === strtolower($type) => 'un tableau littéral `[...]` ou `new \ArrayIterator([...])`',
            class_exists(self::ARRAY_COLLECTION) && is_a(self::ARRAY_COLLECTION, $type, true) => '`new \Doctrine\Common\Collections\ArrayCollection([...])`',
            default => sprintf('une instance de `\%s` (voir son constructeur dans le contexte)', $type),
        };

        // Classe des éléments : `iterable<\App\X>`, `Collection<int, \App\X>` ou `\App\X[]`
        $item = null !== $valueType && 1 === preg_match('/(\\\\[A-Za-z_][\w\\\\]*)(?:>|\[\])/', $valueType, $x) ? $x[1] : null;

        return 'passe ' . $wrap . (null !== $item ? sprintf(', contenant des instances de `%s`', $item) : '') . '.';
    }

    private function valueType(string $class, string $method, string $arg): ?string
    {
        if (1 === preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $class, $m)) {
            $class = $m[1];
        }
        if (!class_exists($class) && !interface_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod($method) ? $this->docTypes->paramType($reflection->getMethod($method), $arg) : null;
    }
}