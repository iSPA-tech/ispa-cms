<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared shape of the placement content routes (Unified Widget Catalog Part 2, Task 3):
 * placing a widget and editing its content need only the layout edit permission (spec
 * "Permissions"), an Idempotency-Key header and the draft revision (`lock_version`) the editor
 * last saw. Permission is checked before validation, like every Widget FormRequest.
 */
abstract class AbstractWidgetPlacementContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_layout.update') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to update this Widget site layout draft.',
        ));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            '_idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            '_idempotency_key' => ['required', 'string', 'max:191'],
            'expected_draft_revision' => ['required', 'integer', 'min:0'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->input('_idempotency_key');
    }

    public function expectedDraftRevision(): int
    {
        return $this->integer('expected_draft_revision');
    }
}
