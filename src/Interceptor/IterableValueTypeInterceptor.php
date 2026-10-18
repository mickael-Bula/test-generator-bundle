<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Reflection\DocTypeResolver;

/** @noinspection PhpUnused */
final readonly class IterableValueTypeInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/Property (?<test>[\w\\\\]+)::\$(?<prop>\w+) type has no value type specified in iterable type (?<type>\S+?)\./';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private DocTypeResolver $docTypes,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    /**
     * @throws \ReflectionException
     */
    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $errors, PREG_SET_ORDER);
        $sut = $this->locator->locate($errorOutput)->sut;

        $md = '';
        foreach ($errors as $e) {
            $docType = null !== $sut ? $this->docTypeFromSut($sut, $e['prop']) : null;
            $md .= sprintf("### Type de valeur manquant : `\$%s` (%s)\n", $e['prop'], $e['type']);
            $md .= null !== $docType
                ? sprintf("- Le SUT déclare ce paramètre `%s` (PHPDoc du constructeur).\n- Correction : ajoute au-dessus de la propriété `/** @var %s */`.\n", $docType, $docType)
                : sprintf("- Aucun type de valeur trouvé dans le SUT : utilise `/** @var %s<object> */` au-dessus de la propriété.\n", $e['type']);
        }

        return $md . "FQCN complets avec antislash initial. Ne change ni la valeur passée au SUT ni la structure du test.\n";
    }

    private function docTypeFromSut(string $sut, string $param): ?string
    {
        $constructor = class_exists($sut) ? (new \ReflectionClass($sut))->getConstructor() : null;

        return null === $constructor ? null : $this->docTypes->paramType($constructor, $param);
    }
}