<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

use Mika\TestGeneratorBundle\Quality\CommentPolicy;
use Mika\TestGeneratorBundle\Quality\QualityViolation;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 99)]
final readonly class CommentCleanerPreFixer implements TestPreFixerInterface
{
    public function __construct(private CommentPolicy $policy)
    {
    }

    public function fix(string $errorOutput, string $testCode): ?string
    {
        // Agit seulement si la porte de qualité a signalé des commentaires (jamais en mode fusion, voir plus bas)
        if ([] === QualityReportReader::targets($errorOutput, QualityViolation::FORBIDDEN_COMMENT)) {
            return null;
        }

        $items = $this->policy->violations($testCode);

        return [] === $items ? null : $this->policy->apply($testCode, $items);
    }
}