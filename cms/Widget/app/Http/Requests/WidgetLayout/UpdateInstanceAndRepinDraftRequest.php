<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin`
 * request shape (plan Chunk 2 Task 18; fixture versions-and-repin route
 * entry: `request_fields: [expected_current_instance_version,
 * expected_draft_id, expected_draft_lock_version, usage_fingerprint,
 * widget_id, widget_theme_id, values, provider_contract]`).
 *
 * AUTHORIZES BOTH RESOURCES (fixture note: "authorizes both Layout and
 * Instance resources"): `widget_layout.update` AND `widget_instance.update`
 * are both required, mirroring the PERMISSION-BEFORE-VALIDATION precedent
 * every other Widget FormRequest in this module already established
 * ({@see \Cms\Widget\Http\Requests\WidgetInstance\UpdateWidgetInstanceRequest},
 * {@see UpdateWidgetLayoutDraftRequest}) -- a denial of EITHER permission
 * renders the SAME {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED}
 * (403) envelope before any 404/409/422 domain check runs.
 *
 * SHAPE ONLY: `expected_current_instance_version` is the Instance's current
 * VERSION NUMBER (never a raw row id -- see
 * {@see \Cms\Widget\Http\Requests\WidgetInstance\UpdateWidgetInstanceRequest}'s
 * identical precedent); `expected_draft_id` is, despite its literal name,
 * ALSO compared against a version NUMBER (the Draft's), not a raw row id --
 * see {@see \Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft}'s
 * class docblock for the full disclosed interpretation.
 * `usage_fingerprint` is an opaque server-recomputed comparison string;
 * this class only checks it is present, never parses it.
 */
final class UpdateInstanceAndRepinDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_layout.update') === true
            && $this->user()?->can('widget_instance.update') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to update this Widget instance and repin this Widget site layout draft.',
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
            'expected_current_instance_version' => ['required', 'integer', 'min:1'],
            'expected_draft_id' => ['required', 'integer', 'min:1'],
            'expected_draft_lock_version' => ['required', 'integer', 'min:0'],
            'usage_fingerprint' => ['required', 'string'],
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
