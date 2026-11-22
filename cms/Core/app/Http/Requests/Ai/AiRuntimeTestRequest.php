<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Ai;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiRuntimeTestRequest extends FormRequest
{
    use RedirectFailedTrait;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                'string',
                Rule::in(array_keys(AiRuntimeSettingsService::DRIVERS)),
            ],
            'model' => ['nullable', 'string', 'max:140'],
        ];
    }
}
