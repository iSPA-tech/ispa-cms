<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Ai;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiRuntimeTasksRequest extends FormRequest
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
            'tasks' => ['required', 'array', 'min:1', 'max:200'],
            'tasks.*.task' => ['required', 'string', 'max:160'],
            'tasks.*.provider' => [
                'nullable',
                'string',
                Rule::in(['', ...array_keys(AiRuntimeSettingsService::DRIVERS)]),
            ],
            'tasks.*.model' => ['nullable', 'string', 'max:140'],
            'tasks.*.temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'tasks.*.fallback_models' => ['nullable', 'string', 'max:500'],
            'tasks.*.reasoning_effort' => ['nullable', 'string', Rule::in(['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'])],
        ];
    }
}
