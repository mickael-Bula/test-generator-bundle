<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;


use Psr\Cache\InvalidArgumentException;

final readonly class SeedExtractor
{
    public function __construct(private RepoMapProvider $maps, private string $rootNamespace = 'App\\') {}

    /**
     * @throws InvalidArgumentException
     */
    public function extract(string $phpunitOutput, ?string $testFile = null, ?string $sut = null): array
    {
        $classes = $this->maps->get()->classes;
        $seeds = null !== $sut ? [$sut] : [];

        // FQCN cités (message d'erreur, trace)
        preg_match_all('/' . preg_quote($this->rootNamespace, '/') . '[\w\\\\]+/', $phpunitOutput, $m);
        foreach ($m[0] as $c) {
            $seeds[] = preg_replace('/::.*$/', '', $c);
        }

        // Fichiers src/ de la trace -> classes
        $byFile = array_column(
            array_map(fn($c, $d) => ['f' => $d['file'], 'c' => $c], array_keys($classes), $classes),
            'c', 'f'
        );
        preg_match_all('#(/\S+?\.php):\d+#', $phpunitOutput, $f);
        foreach ($f[1] as $file) {
            if (isset($byFile[$file])) $seeds[] = $byFile[$file];
        }

        $imports = [];
        if (null !== $testFile && is_file($testFile)) {
            // Classes importées par le test (mocks, collaborateurs)
            preg_match_all('/^use\s+(' . preg_quote($this->rootNamespace, '/') . '[\w\\\\]+);/m', (string) file_get_contents($testFile), $u);
            $imports = $u[1];
        }

        return array_values(array_unique([...$seeds, ...$imports]));
    }
}
