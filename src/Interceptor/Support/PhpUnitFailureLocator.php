<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final readonly class PhpUnitFailureLocator
{
    // "1) App\Tests\Dynamic\PositionManagerDynamicTest::testXxx" (avec ou sans "with data set")
    private const PATTERN = '/^\d+\)\s+(?<c>[\w\\\\]+)::(?<m>\w+)/m';
    public function __construct(private RepoMapProvider $maps) {}

    public function locate(string $output): PhpUnitFailure
    {
        preg_match(self::PATTERN, $output, $t);
        $testClass = $t['c'] ?? null;

        if (!preg_match(self::PATTERN, $output, $t)) {
            preg_match('/(?<c>[\w\\\\]+Test)::\$\w+/', $output, $t); // PHPStan : "Property X\FooTest::$bar …"
        }

        return new PhpUnitFailure(
            $testClass,
            $t['m'] ?? null,
            $this->findTestFile($output, $testClass),
            $this->findSut($output, $testClass),
        );
    }

    private function findTestFile(string $output, ?string $testClass): ?string
    {
        if (null === $testClass) {
            return null;
        }

        // 1. Depuis la trace : ".../PositionManagerDynamicTest.php:141"
        $short = basename(str_replace('\\', '/', $testClass));
        if (preg_match('#(?<f>/\S*?' . preg_quote($short, '#') . '\.php):\d+#', $output, $m) && is_file($m['f'])) {
            return $m['f'];
        }

        // 2. Repli : autoload
        try {
            return (new \ReflectionClass($testClass))->getFileName() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findSut(string $output, ?string $testClass): ?string
    {
        $classes = $this->maps->get()->classes;
        $shortOf = static fn (string $fqcn): string => basename(str_replace('\\', '/', $fqcn));

        // 1. Convention : PositionManagerDynamicTest -> PositionManager
        if (null !== $testClass) {
            $base = preg_replace('/(Dynamic|Unit)?Test$/', '', $shortOf($testClass));
            $candidates = array_values(array_filter(
                array_keys($classes),
                static fn (string $c): bool => $shortOf($c) === $base,
            ));
            if (1 === \count($candidates)) {
                return $candidates[0];
            }
        }

        // 2. Repli : première classe de src/ rencontrée dans la trace
        $fileToClass = array_flip(array_map(static fn (array $d): string => $d['file'], $classes));
        preg_match_all('#(/\S+?\.php):\d+#', $output, $f);
        foreach ($f[1] as $file) {
            if (isset($fileToClass[$file])) {
                return $fileToClass[$file];
            }
        }

        return null;
    }
}