<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Service;

use Mika\TestGeneratorBundle\Refactor\FqcnImportRefactorer;

final readonly class TestFinalizer
{
    public function __construct(
        private FqcnImportRefactorer $refactorer,
        private TestGeneratorV2 $generator,
    ) {
    }

    /**
     * Remplace les FQCN en ligne par des `use`, et ne livre cette version que si elle reste verte.
     *
     * @param string $greenCode test validé (PHPUnit + PHPStan), au format App\Tests\Dynamic
     *
     * @return array{code: string, refactored: bool, reason: ?string}
     */
    public function finalize(string $greenCode, string $className): array
    {
        $refactored = $this->refactorer->refactor($greenCode);
        if ($refactored === $greenCode) {
            return ['code' => $greenCode, 'refactored' => false, 'reason' => null];
        }

        $check = $this->generator->check($refactored, $className);
        if ($check['valid']) {
            return ['code' => $refactored, 'refactored' => true, 'reason' => null];
        }

        return [
            'code' => $greenCode,
            'refactored' => false,
            'reason' => sprintf('La version avec `use` échoue (%s) : FQCN conservés en ligne.', $check['phase']),
        ];
    }
}