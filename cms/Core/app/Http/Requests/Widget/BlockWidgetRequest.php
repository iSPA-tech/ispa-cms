<?php

namespace Cms\Core\Http\Requests\Widget;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Emergency block of a widget: the operator must say why. Permission (`widget.block`) is checked in
 * WidgetController, like the other widget actions.
 */
class BlockWidgetRequest extends FormRequest
{
    use RedirectFailedTrait;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
