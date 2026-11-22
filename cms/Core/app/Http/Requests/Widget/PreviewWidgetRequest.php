<?php

namespace Cms\Core\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;
use Cms\Core\Http\RedirectFailedTrait;

class PreviewWidgetRequest extends FormRequest
{
    use RedirectFailedTrait;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', 'min:1'],
            'edit_markers' => ['sometimes', 'boolean'],
        ];
    }
}
