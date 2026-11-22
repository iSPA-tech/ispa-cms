<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetInstance;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /api/v2/admin/widget/instances` request shape (plan Chunk 2 Task
 * 16; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * instances-create route entry:
 * `request_fields: [name, widget_id, widget_theme_id, values, provider_contract]`).
 *
 * IDEMPOTENCY KEY TRANSPORT: the frozen `request_fields` list carries no
 * `idempotency_key` field, and docs/architecture/adr-widget-mutation-idempotency.md
 * (decision, "Generic HTTP Idempotency-Key header middleware — rejected")
 * names `Idempotency-Key` as this codebase's chosen header for the concept
 * while explicitly rejecting a generic middleware interceptor — so each
 * controller/request reads it explicitly. `prepareForValidation()` merges
 * it into a virtual field so a missing/blank key fails the SAME validation
 * pass as every other required field.
 *
 * PERMISSION-BEFORE-VALIDATION (precedent copied from
 * {@see \Cms\Widget\Http\Requests\WidgetManifest\MaterializeWidgetManifestRequest}):
 * `authorize()` performs the `widget_instance.create` check and renders its
 * denial through the shared {@see WidgetApiErrorResponse} envelope as
 * {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} (403), reached
 * BEFORE rules() validation.
 *
 * DISCLOSED CHOICE: request-SHAPE validation (this class's own
 * `rules()`/`failedValidation()`) is deliberately left as Laravel's DEFAULT
 * JSON validation response (422, `{message, errors}`), NOT the Widget
 * module's envelope — the frozen 26-code error dictionary has no
 * Instance-route-specific request-shape code (unlike materialize/approval/
 * runtime-block, which each got one via a dedicated "contract evolution"),
 * and this task must add no new code. Reusing an ill-fitting existing code
 * (e.g. WIDGET_MANIFEST_SOURCE_INVALID, whose own definition is scoped to
 * Manifest-source DOMAIN validation) would misrepresent a plain missing
 * `name` field as a manifest-source problem. DOMAIN-level 422s the SERVICE
 * itself detects (invalid pairing, inapplicable project, pending approval)
 * DO reuse the existing Manifest-lifecycle codes — see
 * {@see \Cms\Widget\Http\Controllers\V2\Admin\WidgetInstanceController}.
 */
final class StoreWidgetInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_instance.create') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to create Widget instances.',
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
            'name' => ['required', 'string', 'max:255'],
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
