<?php

namespace Cms\Core\Http\Requests\Field;

class PreviewFieldRequest extends StoreFieldRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'id' => 'nullable|integer|min:0',
            'name' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:32',
        ]);
    }
}
