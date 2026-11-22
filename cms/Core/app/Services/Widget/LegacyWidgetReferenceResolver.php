<?php

namespace Cms\Core\Services\Widget;

use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;

final class LegacyWidgetReferenceResolver
{
    /** @return array{widget_id:int, template_id:int, widget_key:string, theme_key:string, inputs:array<int, mixed>}|null */
    public function resolve(
        int $widgetId,
        int $themeId,
        string $widgetKey = '',
        string $themeKey = '',
    ): ?array {
        if ($widgetKey !== '' && $themeKey !== '') {
            $widget = Widget::query()->with('inputs')->where('widget_key', $widgetKey)->first();
            $theme = $widget === null ? null : WidgetTheme::query()
                ->where('widget_id', $widget->id)
                ->where('theme_key', $themeKey)
                ->first();
        } else {
            $widget = Widget::query()->with('inputs')->find($widgetId);
            $theme = $widget === null ? null : WidgetTheme::query()
                ->whereKey($themeId)
                ->where('widget_id', $widget->id)
                ->first();
        }

        if ($widget === null || $theme === null) {
            return null;
        }

        return [
            'widget_id' => (int) $widget->id,
            'template_id' => (int) $theme->id,
            'widget_key' => (string) $widget->widget_key,
            'theme_key' => (string) $theme->theme_key,
            'inputs' => $widget->inputs->toArray(),
        ];
    }

    /** @param array<int, mixed> $content @return array<int, mixed> */
    public function enrichBlocks(array $content): array
    {
        return array_map(function (mixed $item): mixed {
            if (!is_array($item) || ($item['type'] ?? null) !== 'widget') {
                return $item;
            }

            $identity = $this->resolve(
                (int) ($item['widget_id'] ?? 0),
                (int) ($item['template_id'] ?? 0),
                (string) ($item['widget_key'] ?? ''),
                (string) ($item['theme_key'] ?? ''),
            );

            return $identity === null ? $item : array_replace($item, $identity);
        }, $content);
    }
}
