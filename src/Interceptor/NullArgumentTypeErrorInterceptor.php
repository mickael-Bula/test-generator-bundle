<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Interceptor\Support\RepoMapProvider;
use Mika\TestGeneratorBundle\Interceptor\Support\SeedExtractor;
use Mika\TestGeneratorBundle\Parser\TypeErrorInfo;
use Mika\TestGeneratorBundle\Renderer\SkeletonRenderer;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/** @noinspection PhpUnused */
final readonly class NullArgumentTypeErrorInterceptor implements ErrorInterceptorInterface
{
    public function __construct(
        private RepoMapProvider       $maps,
        private PhpUnitFailureLocator $locator,
        private SeedExtractor         $seeds,
        private NullFlowDiagnostic    $diagnostic,
        private SkeletonRenderer      $renderer,
        private int                   $maxDepth = 2,
        private int                   $tokenBudget = 6000,
        private ?LoggerInterface      $logger = null,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        // Même parseur que le diagnostic : on ne se déclare que si on peut l'exploiter.
        return 'null' === TypeErrorInfo::fromOutput($errorOutput)?->given;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        $failure = $this->locator->locate($errorOutput);

        $seeds = $this->seeds->extract($errorOutput, $failure->testFile, $failure->sut);
        $layers = $this->maps->get()->expand($seeds, $this->maxDepth); // fqcn => profondeur
        asort($layers);

        $parts = [];

        // Diagnostic en tête : best-effort, il ne doit jamais casser la boucle de correction.
        try {
            $parts[] = $this->diagnostic->build($errorOutput, $failure->testFile, array_keys($layers));
        } catch (\Throwable $e) {
            $this->logger?->warning('Diagnostic de nullabilité indisponible : ' . $e->getMessage());
        }

        if ('' !== ($skeletons = $this->skeletons($layers))) {
            $parts[] = "## Signatures réelles des classes concernées (réflexion)\n" . $skeletons;
        }

        $parts = array_filter($parts);

        return [] === $parts ? null : implode("\n", $parts);
    }

    /** @param array<string,int> $layers fqcn => profondeur, trié par profondeur croissante */
    private function skeletons(array $layers): string
    {
        $out = '';
        foreach ($layers as $fqcn => $depth) {
            try {
                $block = $this->renderer->render($fqcn, $depth);
            } catch (\ReflectionException) {
                continue; // classe non chargeable : on l'ignore
            }
            if ((\strlen($out) + \strlen($block)) / 4 > $this->tokenBudget) {
                break;
            }
            $out .= "// --- profondeur $depth ---\n$block\n";
        }

        return $out;
    }

    public function intercept2(string $errorOutput): ?string
    {
        // Capture n'importe quel TypeError du type : Class::method(): Argument #X ($arg) must be of type T, null given
        if (!preg_match(
            '/TypeError:\s+([^:]+)::([^(]+)\(\):\s+Argument\s+#(\d+)\s+\(\$([^)]+)\)\s+must be of type\s+([^,]+),\s+null given/i',
            $errorOutput,
            $matches
        )) {
            return null;
        }

        $className = $matches[1];
        $methodName = $matches[2];
        $argPosition = (int)$matches[3];
        $argName = $matches[4];
        $expectedType = trim($matches[5]);

        $reflectionDetail = sprintf(
            "   - Cible en cause dans la SUT : variable `\$%s` (Argument #%s de `%s::%s()`, Type attendu : `%s`)",
            $argName,
            $argPosition,
            $className,
            $methodName,
            $expectedType
        );

        $dtoBlueprint = '';

        try {
            $resolvedClass =$className;
            if (str_contains($className, 'MockObject_')
                && preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $className,$m)
            ) {
                if (class_exists($m[1]) || interface_exists($m[1])) {
                    $resolvedClass = $m[1];
                }
            }

            if (class_exists($resolvedClass)) {
                $refClass = new \ReflectionClass($resolvedClass);
                if ($refClass->hasMethod($methodName)) {
                    $methodRef = $refClass->getMethod($methodName);
                    $parameters = $methodRef->getParameters();

                    // Ciblage précis de l'argument en erreur dans la SUT
                    if (isset($parameters[$argPosition - 1])) {
                        $targetParam = $parameters[$argPosition - 1];
                        $paramType = $targetParam->getType();

                        if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                            $dtoClass = $paramType->getName();
                            if (class_exists($dtoClass)) {
                                $dtoRef = new \ReflectionClass($dtoClass);
                                $dtoConstructor = $dtoRef->getConstructor();
                                if ($dtoConstructor) {
                                    $constructorParams = [];
                                    foreach ($dtoConstructor->getParameters() as $dtoParam) {
                                        $typeHint = $dtoParam->hasType() ? ': ' . $dtoParam->getType()->getName() : '';
                                        $constructorParams[] = sprintf('%s%s: <valeur_requise>', $dtoParam->getName(),$typeHint);
                                    }

                                    $shortDtoName = basename(str_replace('\\', '/', $dtoClass));
                                    $dtoBlueprint = sprintf(
                                        "\n\nBlueprint structurel par Réflexion du DTO/Objet (`%s`) :\n```php\nnew %s(\n    %s\n);\n```",
                                        $dtoClass,
                                        $shortDtoName,
                                        implode(",\n    ", $constructorParams)
                                    );
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback silencieux en cas d'erreur de réflexion
        }

        return sprintf(
            "INDICE DE CORRECTION GÉNÉRIQUE (TYPAGE STRICT : NULL GIVEN) :\n"
            . "Erreur fatale : La méthode `%s::%s()` a reçu la valeur `null` pour l'argument `\$%s` (Argument #%s).\n"
            . "%s"
            . "%s\n\n"
            . "✔️ **Pistes de résolution universelles dans ton test** :\n"
            . "1. **Si cette donnée provient d'un Mock** : Configure explicitement sa valeur de retour via `->willReturn(...)` (les mocks retournent `null` par défaut).\n"
            . "2. **Si cette donnée provient d'un DTO / Objet** : Fournis **l'intégralité** des arguments requis dans son constructeur en utilisant le template structurel ci-dessus pour qu'aucun getter ne retourne `null`.",
            $className,
            $methodName,
            $argName,
            $argPosition,
            $reflectionDetail,
            $dtoBlueprint
        );
    }
}
