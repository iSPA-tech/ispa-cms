<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v2/admin/widget/layouts/by-key/{layout_key}` request shape (plan
 * Chunk 2 Task 17; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * bootstrap route entry: `request_fields: []`, `allowlisted_keys: ["site"]`).
 *
 * The route carries NO body fields at all -- the only thing to validate is
 * the `{layout_key}` ROUTE parameter itself against the frozen allowlist.
 * `prepareForValidation()` merges it into a virtual field so an unknown key
 * fails validation the same way a malformed body field would.
 *
 * PERMISSION-BEFORE-VALIDATION (precedent copied from
 * {@see \Cms\Widget\Http\Requests\WidgetInstance\StoreWidgetInstanceRequest}):
 * `authorize()` performs the `widget_layout.update` check and renders its
 * denial through the shared {@see WidgetApiErrorResponse} envelope as
 * {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} (403), reached
 * BEFORE rules() validation.
 *
 * DISCLOSED CHOICE: an unknown `layout_key` is left as Laravel's DEFAULT
 * JSON validation response (422, `{message, errors}`), NOT the Widget
 * module's envelope -- identical reasoning to
 * {@see StoreWidgetInstanceRequest}'s own disclosed choice: the frozen
 * 26-code error dictionary has no code fitting "unknown Layout bootstrap
 * key" and this task must add no new code. This route's own fixture entry
 * declares no `error_codes` mapping (unlike materialize/approval/
 * runtime-block), so there is no evolution precedent to extend here either.
 */
final class BootstrapWidgetLayoutRequest extends FormRequest
{
    public const ALLOWED_KEYS = ['site'];

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
            'You do not have permission to bootstrap this Widget site layout.',
        ));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            '_layout_key' => $this->route('layout_key'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            '_layout_key' => ['required', 'string', Rule::in(self::ALLOWED_KEYS)],
        ];
    }

    public function layoutKey(): string
    {
        return (string) $this->input('_layout_key');
    }
}
