<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use Cms\Core\Services\Widget\LegacyWidgetReferenceResolver;
use Closure;
use JsonException;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;

class WidgetView extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public int   $widget = 0,
        public int   $templateId = 0,
        public array $value = [],
        public array $inputs = [],
        public bool  $template = false,
        public string $widgetKey = '',
        public string $themeKey = '',
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @throws JsonException
     */
    public function render(): View|Closure|string
    {
        [$widgetId, $themeId] = $this->resolveStableIds();

        $inputs = collect($this->inputs);

        foreach ($this->value as $name => $val) {
            $input = $inputs->firstWhere('name', $name);

            // Skip checking if value is empty, allow it to pass
            if (empty($val)) {
                continue; // ✅ Just skip this value and continue
            }

            // Special inputs need a value before rendering; a populated query/iSeo value is valid.
            if (!empty($input) && is_array($input) && isset($input['type']) && in_array($input['type'], ['iSeo', 'query'], true)) {
                if (empty($val)) {
                return ''; // 🚫 Stop rendering only for iSeo or query with empty value
                }
            }
        }

        // Proceed to render if all values passed validation. Delegates to the
        // root WidgetRenderer contract (bound by default to
        // App\Support\Widget\LegacyWidgetRenderer, which wraps the same
        // WidgetQueryService::preRender() path) instead of calling
        // WidgetQueryService directly, so cms/Widget can rebind rendering
        // without this component ever resolving a cms/Widget module class itself.
        $result = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widgetId, $themeId, $this->value),
            new WidgetRenderContext(projectName: (string) config('ispa-cms.folder_name', 'Default')),
        );

        $htmlData = $result->html;

        $viewName = 'components.widget-view';

        if ($this->template) {
            $projectViewName = (string) config('ispa-cms.folder') . $viewName;

            if (view()->exists($projectViewName)) {
                $viewName = $projectViewName;
            }
        }

        return view($viewName, [
            'view' => $htmlData,
        ]);
    }

    /** @return array{int, int} */
    private function resolveStableIds(): array
    {
        if ($this->widgetKey === '' && $this->themeKey === '') {
            return [$this->widget, $this->templateId];
        }

        $identity = app(LegacyWidgetReferenceResolver::class)->resolve(
            $this->widget,
            $this->templateId,
            $this->widgetKey,
            $this->themeKey,
        );

        return $identity === null
            ? [0, 0]
            : [$identity['widget_id'], $identity['template_id']];
    }
}
