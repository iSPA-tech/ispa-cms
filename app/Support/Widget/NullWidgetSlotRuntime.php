<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\WidgetSlotRuntime;

/**
 * Bound default for WidgetSlotRuntime. Always answers "nothing placed"
 * (ok = false, empty html) without resolving any cms/Widget module class, so the
 * root <x-widget-slot> component renders its supplied fallback content
 * once, or empty output when no fallback was supplied.
 */
final class NullWidgetSlotRuntime implements WidgetSlotRuntime
{
    public function render(string $slotKey, WidgetRenderContext $context): WidgetRenderResult
    {
        return new WidgetRenderResult(html: '', ok: false, errorCode: 'WIDGET_MODULE_DISABLED');
    }
}
