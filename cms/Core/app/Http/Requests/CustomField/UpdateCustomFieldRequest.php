<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\CustomField;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check for a change to an existing custom field. Every key is optional; absent keys keep their value.
 *
 * `key` may be sent only unchanged (CustomFieldService refuses a different key). The meaning of the definition is
 * checked by CustomFieldService. Authorization happens in the controller before this request is resolved.
 */
class UpdateCustomFieldRequest extends FormRequest
{
    /**
     * Authorization is done by the controller (resource and permission checks) before resolving this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules of the change payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:40'],
            'type' => ['sometimes', 'string', 'max:20'],
            'labels' => ['sometimes', 'array'],
            'help' => ['sometimes', 'nullable', 'array'],
            'rules' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array', 'max:200'],
            'is_required' => ['sometimes', 'boolean'],
            'show_in_list' => ['sometimes', 'boolean'],
            'is_searchable' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
        ];
    }
}
