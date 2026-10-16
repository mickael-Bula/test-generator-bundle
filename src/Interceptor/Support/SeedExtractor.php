<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;


final readonly class SeedExtractor
{
    public function __construct(private RepoMapProvider $maps, private string $rootNs = 'App\\') {}

    public function extract(string $phpunitOutput, string $testFile, string $sut): array
    {
        $seeds = [$sut];

        // FQCN cités (message d'erreur, trace)
        preg_match_all('/' . preg_quote($this->rootNs, '/') . '[\w\\\\]+/', $phpunitOutput, $m);
        foreach ($m[0] as $c) {
            $seeds[] = preg_replace('/::.*$/', '', $c);
        }

        // Fichiers src/ de la trace -> classes
        $byFile = array_column(
            array_map(fn($c, $d) => ['f' => $d['file'], 'c' => $c], array_keys($this->maps->get()->classes), $this->maps->get()->classes),
            'c', 'f'
        );
        preg_match_all('#(/\S+?\.php):\d+#', $phpunitOutput, $f);
        foreach ($f[1] as $file) {
            if (isset($byFile[$file])) $seeds[] = $byFile[$file];
        }

        // Classes importées par le test (mocks, collaborateurs)
        preg_match_all('/^use\s+(' . preg_quote($this->rootNs, '/') . '[\w\\\\]+);/m', file_get_contents($testFile), $u);

        return array_values(array_unique([...$seeds, ...$u[1]]));
    }
}
