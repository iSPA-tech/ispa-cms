<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/rollback` request shape (plan
 * Chunk 2 Task 21; fixture rollback route entry: `request_fields:
 * [source_release_version, expected_draft_id, expected_lock_version]`).
 *
 * PERMISSION-BEFORE-VALIDATION (precedent copied from
 * {@see PublishWidgetLayoutRequest}/{@see UpdateInstanceAndRepinDraftRequest}):
 * `authorize()` performs the `widget_layout.rollback` check and renders its
 * denial through the shared {@see WidgetApiErrorResponse} envelope as
 * {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} (403), reached
 * BEFORE rules() validation (403-before-422 precedent).
 *
 * SHAPE ONLY: `source_release_version` and `expected_draft_id` are, despite
 * their literal names, compared against Layout Version VERSION NUMBERS, not
 * raw internal row ids -- identical disclosed interpretation to every other
 * Layout-Version-identifying field in this module (Tasks 17/18/20's own
 * precedents): `widget_layout_versions` carries no uuid of its own, and the
 * frozen `WidgetLayoutResource.current_draft`/`WidgetLayoutReleaseResource`
 * fields expose only `version` (never a raw row id) anywhere in this frozen
 * contract.
 */
final class RollbackWidgetLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_layout.rollback') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to roll back this Widget site layout.',
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
            'source_release_version' => ['required', 'integer', 'min:1'],
            'expected_draft_id' => ['required', 'integer', 'min:1'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->input('_idempotency_key');
    }
}
