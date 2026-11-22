<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Ai;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the monthly LLM budget payload of the AI runtime admin page.
 */
class AiRuntimeBudgetRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Authorization is enforced by the route group middleware.
     */
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
            'monthly_usd' => ['nullable', 'numeric', 'min:0'],
            'kill_switch' => ['nullable', 'boolean'],
            'warning_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'spike_enabled' => ['nullable', 'boolean'],
            'spike_multiplier' => ['nullable', 'numeric', 'min:1', 'max:20'],
            'spike_min_usd' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
