<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetInstance;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `PATCH /api/v2/admin/widget/instances/{uuid}` request shape (plan Chunk
 * 2 Task 16; fixture instances-update route entry:
 * `request_fields: [expected_current_version, widget_id, widget_theme_id, values, provider_contract]`).
 *
 * `expected_current_version` is the Instance's CURRENT VERSION NUMBER (the
 * `current_version.version` field the client already read from a prior
 * {@see \Cms\Widget\Http\Resources\V2\Admin\WidgetInstanceResource}
 * response) — NEVER a raw internal `widget_instance_versions.id` row id.
 * `widget_instance_versions` carries no uuid of its own; the version number
 * scoped to its owning Instance is the only client-facing identifier,
 * consistent with this codebase's UUID-first admin-API rule of never
 * requiring a caller to echo back a raw internal row id (see
 * {@see \Cms\Widget\Instances\WidgetInstanceVersionAllocator}, which
 * performs the actual recheck against this same number).
 *
 * See {@see StoreWidgetInstanceRequest}'s class docblock for the
 * `Idempotency-Key` header transport and the disclosed request-shape 422
 * choice (Laravel's default JSON validation response, not the Widget
 * envelope) — both apply identically here.
 */
final class UpdateWidgetInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_instance.update') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to update this Widget instance.',
        ));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            '_idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            '_idempotency_key' => ['required', 'string'],
            'expected_current_version' => ['required', 'integer', 'min:1'],
            'widget_id' => ['required', 'integer', 'min:1'],
            'widget_theme_id' => ['required', 'integer', 'min:1'],
            'values' => ['nullable', 'array'],
            'provider_contract' => ['nullable', 'array'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->input('_idempotency_key');
    }
}
