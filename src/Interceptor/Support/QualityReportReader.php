<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor\Support;

final class QualityReportReader
{
    /**
     * @return array<string, true> noms des tests (ou méthodes) signalés pour cette règle
     */
    public static function targets(string $report, string $rule): array
    {
        preg_match_all('/^\d+\)\s+[\w\\\\]+::(?<test>\w+)\R\[' . preg_quote($rule, '/') . '\]/m', $report, $m);

        return array_fill_keys($m['test'], true);
    }
}