<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\CustomField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check for a new custom field definition (also used by the preview endpoint).
 *
 * Only the payload shape is checked here; the meaning of a definition (key format, type, both-language labels, rule
 * limits, options) is checked by CustomFieldService / CustomFieldRules, which answer with operator-safe messages.
 * Authorization happens in the controller before this request is resolved, so a forbidden or unknown resource never
 * reaches validation. Failures use Laravel's default JSON 422 response.
 */
class StoreCustomFieldRequest extends FormRequest
{
    /**
     * Authorization is done by the controller (resource and permission checks) before resolving this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules of the definition payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:40'],
            'type' => ['required', 'string', 'max:20'],
            'labels' => ['required', 'array'],
            'help' => ['nullable', 'array'],
            'rules' => ['nullable', 'array'],
            'options' => ['nullable', 'array', 'max:200'],
            'is_required' => ['sometimes', 'boolean'],
            'show_in_list' => ['sometimes', 'boolean'],
            'is_searchable' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
        ];
    }
}
