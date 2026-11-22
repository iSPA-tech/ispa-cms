<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;

/**
 * Secure root default for CanonicalWidgetSchemaResolver. Loads canonical
 * Core-owned Widget/WidgetInput relations directly from the database on
 * every call — it never trusts a copied/cached schema (e.g. the `inputs`
 * array a Page/Post block stores alongside a Widget reference). This keeps
 * validating Inline input whether cms/Widget is enabled or disabled
 * (adr-widget-module-boundary.md decision 4).
 */
final class CanonicalWidgetSchema implements CanonicalWidgetSchemaResolver
{
    public function resolve(int $widgetId, int $themeId): array
    {
        if (!$this->isValidPairing($widgetId, $themeId)) {
            return [];
        }

        $widget = Widget::query()->with('inputs')->find($widgetId);

        if ($widget === null) {
            return [];
        }

        $definitions = [];

        foreach ($widget->inputs as $input) {
            $name = (string) $input->name;

            if ($name === '') {
                continue;
            }

            $type = (string) ($input->type ?: 'text');
            $definitions[$name] = ['type' => $type];

            // Options are exposed for repeater inputs only so the fingerprints of other inputs stay unchanged.
            if ($type === 'repeater') {
                $options = $input->options;
                $definitions[$name]['options'] = is_array($options) ? $options : [];
            }
        }

        return $definitions;
    }

    public function isValidPairing(int $widgetId, int $themeId): bool
    {
        if ($widgetId <= 0 || $themeId <= 0) {
            return false;
        }

        $theme = WidgetTheme::query()->find($themeId);

        return $theme !== null && (int) $theme->widget_id === $widgetId && Widget::query()->whereKey($widgetId)->exists();
    }
}
