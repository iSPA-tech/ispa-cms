<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

/**
 * `PATCH /layouts/{uuid}/draft/placements/{placement_uuid}/content`: new content (and
 * optionally another theme of the same widget) for one placement.
 */
final class UpdateWidgetPlacementContentRequest extends AbstractWidgetPlacementContentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'values' => ['present', 'array'],
            'widget_theme_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function themeId(): ?int
    {
        return $this->filled('widget_theme_id') ? $this->integer('widget_theme_id') : null;
    }
}
