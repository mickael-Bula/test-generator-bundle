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

        $parts[] = "### Pistes pour un `null` reçu par un argument\n"
            . "1. Un mock renvoie `null` par défaut : configure `->willReturn(...)`.\n"
            . "2. Un DTO ou une entité mal construit : fournis tous les arguments du constructeur, ou appelle les mutateurs nécessaires.\n"
            . "3. Un mutateur retourne `void` : `\$x = (new X())->setA(1);` affecte `null` à `\$x`. Crée l'objet, puis appelle ses mutateurs un par un.\n";

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
}
