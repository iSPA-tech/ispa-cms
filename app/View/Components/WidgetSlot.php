<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetSlotRuntime;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Always-available root Slot component. Contains no registry, query,
 * persistence, provider, artifact, or rendering implementation of its own
 * — it only resolves WidgetSlotRuntime from the container at render time.
 * With cms/Widget disabled (the bound NullWidgetSlotRuntime default),
 * project Blade call sites stay safe: the component renders its supplied
 * fallback slot content once, or empty output when no fallback exists.
 *
 * Usage: <x-widget-slot slot-key="header">Fallback content</x-widget-slot>
 */
class WidgetSlot extends Component
{
    public bool $hasContent;

    public string $html;

    public function __construct(
        public string $slotKey,
        public bool $useCss = false,
    ) {
        $context = new WidgetRenderContext(
            projectName: (string) config('ispa-cms.folder_name', 'Default'),
            useCss: $this->useCss,
        );

        $result = app(WidgetSlotRuntime::class)->render($this->slotKey, $context);

        $this->hasContent = $result->ok && $result->html !== '';
        $this->html = $result->html;
    }

    public function render(): View|Closure|string
    {
        return view('components.widget-slot');
    }
}
