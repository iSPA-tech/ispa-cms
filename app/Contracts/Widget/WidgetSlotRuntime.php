<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;

/**
 * Root Slot-runtime contract resolved by the always-available
 * `<x-widget-slot>` root component. Bound by default to
 * App\Support\Widget\NullWidgetSlotRuntime, which always answers
 * `ok = false` with empty html so the component renders its supplied
 * fallback (or nothing) without ever resolving a cms/Widget module class.
 * The cms/Widget module rebinds this to WidgetSlotRuntimeService in boot().
 */
interface WidgetSlotRuntime
{
    public function render(string $slotKey, WidgetRenderContext $context): WidgetRenderResult;
}
