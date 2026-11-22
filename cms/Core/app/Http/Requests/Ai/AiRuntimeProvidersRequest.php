<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Ai;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiRuntimeProvidersRequest extends FormRequest
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
            'default_provider' => [
                'nullable',
                'string',
                Rule::in(['', ...array_keys(AiRuntimeSettingsService::DRIVERS)]),
            ],
            'providers' => ['required', 'array', 'min:1', 'max:20'],
            'providers.*.key' => [
                'required',
                'string',
                Rule::in(array_keys(AiRuntimeSettingsService::DRIVERS)),
            ],
            'providers.*.model' => ['nullable', 'string', 'max:140'],
            'providers.*.base_uri' => ['nullable', 'string', 'max:255', 'url'],
            'providers.*.api_key' => ['nullable', 'string', 'max:500'],
            'providers.*.clear_api_key' => ['nullable', 'boolean'],
        ];
    }
}
