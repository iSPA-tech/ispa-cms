<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Models\WidgetPlacement;
use Illuminate\Validation\Rule;

/**
 * `POST /layouts/{uuid}/draft/placements`: a placement and its content in one call. Shape only;
 * slot/scope/target/cardinality checks belong to the draft save the service runs.
 */
final class StoreWidgetPlacementRequest extends AbstractWidgetPlacementContentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'slot_key' => ['required', 'string'],
            'scope_type' => ['required', 'string', Rule::in([
                WidgetPlacement::SCOPE_GLOBAL,
                WidgetPlacement::SCOPE_TEMPLATE,
                WidgetPlacement::SCOPE_TARGET,
            ])],
            'scope_key' => ['nullable', 'string', 'required_if:scope_type,' . WidgetPlacement::SCOPE_TEMPLATE],
            'target_type' => ['nullable', 'string', 'required_if:scope_type,' . WidgetPlacement::SCOPE_TARGET],
            'target_id' => ['nullable', 'string', 'required_if:scope_type,' . WidgetPlacement::SCOPE_TARGET],
            'widget_id' => ['required', 'integer', 'min:1'],
            'widget_theme_id' => ['required', 'integer', 'min:1'],
            'values' => ['present', 'array'],
        ];
    }
}
