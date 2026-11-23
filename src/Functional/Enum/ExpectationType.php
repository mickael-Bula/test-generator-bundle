<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Enum;

enum ExpectationType: string
{
    case UrlIs = 'url_is';
    case UrlContains = 'url_contains';
    case TextVisible = 'text_visible';
    case TextAbsent = 'text_absent';
    case PanelOpen = 'panel_open';
    case PanelClosed = 'panel_closed';
    case RowsDelta = 'rows_delta';
    case RowsTotal = 'rows_total';
}
