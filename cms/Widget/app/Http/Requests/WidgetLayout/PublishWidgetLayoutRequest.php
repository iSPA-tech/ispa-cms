<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/publish` request shape (plan
 * Chunk 2 Task 20; fixture publish route entry: `request_fields:
 * [expected_draft_id, expected_lock_version]`).
 *
 * PERMISSION-BEFORE-VALIDATION (precedent copied from
 * {@see BootstrapWidgetLayoutRequest}/{@see UpdateInstanceAndRepinDraftRequest}):
 * `authorize()` performs the `widget_layout.publish` check and renders its
 * denial through the shared {@see WidgetApiErrorResponse} envelope as
 * {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} (403), reached
 * BEFORE rules() validation (403-before-422 precedent).
 *
 * SHAPE ONLY: `expected_draft_id` is, despite its literal name, compared
 * against the Draft's VERSION NUMBER, not a raw row id -- identical
 * disclosed interpretation to
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService::save()}'s
 * own `expected_current_draft_id` and
 * {@see \Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft}'s own
 * `expected_draft_id` (Tasks 17/18): `widget_layout_versions` carries no
 * uuid of its own, and the frozen `WidgetLayoutResource.current_draft`
 * nested field list is exactly `["version", "lock_version"]` -- no raw row
 * id is ever exposed to a client anywhere in this frozen contract. This
 * route's OWN frozen field is `expected_lock_version` (NOT
 * `expected_draft_lock_version` like the repin route) -- the exact literal
 * name is preserved from the frozen fixture.
 */
final class PublishWidgetLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_layout.publish') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to publish this Widget site layout.',
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
            'expected_draft_id' => ['required', 'integer', 'min:1'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->input('_idempotency_key');
    }
}
