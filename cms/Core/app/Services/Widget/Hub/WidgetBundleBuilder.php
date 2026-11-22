<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;

/**
 * Builds an unsigned {@see WidgetBundle} from the widget, designs and inputs stored on this site.
 *
 * `query` and `project_name` (JSON columns) travel decoded; `options` (a text column) travels as its raw string so a
 * site writes back exactly what was published. Per-language names travel when set ({@see WidgetBundle::create()} drops
 * empty ones). Designs carry no local asset files today (images are input values
 * or remote URLs), so `assets` is empty; the format and the verifier already support them.
 */
final class WidgetBundleBuilder
{
    /**
     * @param array<string, string> $previewPngByThemeKey theme_key => base64 PNG
     */
    public function build(string $widgetKey, int $version, string $publisher, array $previewPngByThemeKey = []): WidgetBundle
    {
        $widget = Widget::query()->where('widget_key', $widgetKey)->firstOrFail();

        $designs = WidgetTheme::query()->where('widget_id', $widget->id)->orderBy('theme_key')->get()
            ->map(static fn (WidgetTheme $theme): array => [
                'theme_key' => (string) $theme->theme_key,
                'name' => $theme->name,
                'name_translations' => $theme->name_translations,
                'content' => (string) $theme->content,
                'css' => $theme->css,
                'query' => self::decoded($theme->getRawOriginal('query')),
                'project_name' => self::decoded($theme->getRawOriginal('project_name')),
                'preview_png_base64' => $previewPngByThemeKey[$theme->theme_key] ?? null,
            ])->all();

        $inputs = WidgetInput::query()->where('widget_id', $widget->id)->orderBy('input_key')->get()
            ->map(static fn (WidgetInput $input): array => [
                'default_value' => $input->default_value,
                'input_key' => (string) $input->input_key,
                'name' => $input->name,
                'options' => $input->getRawOriginal('options'),
                'type' => $input->type,
            ])->all();

        return WidgetBundle::create(
            widgetKey: $widgetKey,
            version: $version,
            publisher: $publisher,
            widget: [
                'name' => (string) $widget->name,
                'description' => $widget->description,
                'js' => $widget->js,
                'name_translations' => $widget->name_translations,
                'description_translations' => $widget->description_translations,
            ],
            designs: $designs,
            inputs: $inputs,
            assets: [],
            requires: [
                'renderer' => WidgetBundle::WIDGET_RENDERER_VERSION,
                'resolvers' => WidgetResolverRequirements::forDesigns($designs),
            ],
        );
    }

    private static function decoded(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }
        $value = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $value : $raw;
    }
}
