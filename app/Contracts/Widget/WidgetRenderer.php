<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;

/**
 * Root rendering contract. Bound by default to
 * App\Support\Widget\LegacyWidgetRenderer (wraps the current Inline path
 * unchanged); the cms/Widget module rebinds this to WidgetRenderService in boot().
 * With cms/Widget disabled, Inline Widgets continue through the default.
 */
interface WidgetRenderer
{
    public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult;
}
