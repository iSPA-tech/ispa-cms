<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetManifest;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * `POST /api/v2/admin/widget/catalog/manifests/materialize` request shape
 * (plan Chunk 2 Task 15a; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * materialize route entry).
 *
 * Frozen payload is EXACTLY `{widget_id, widget_theme_id,
 * expected_source_fingerprint}` -- no project selector; project/schema/
 * provider contracts are derived server-side by the controller via
 * {@see \Cms\Widget\Security\WidgetProjectContextResolver}. `project_name`/
 * `project` are explicitly `prohibited` (the fixture's own
 * `forbidden_request_fields`), so a caller-supplied selector is REJECTED
 * (422) rather than silently ignored.
 *
 * PERMISSION-BEFORE-VALIDATION (precedent for every 15b 403 surface):
 * `authorize()` performs the `widget_template.approve` check, and Laravel
 * runs authorize() BEFORE rules() validation -- so a caller lacking the
 * permission gets 403 regardless of how malformed the body is, and only a
 * permitted caller's request ever reaches shape validation (422). Both the
 * 403 (permission) and 422 (shape) responses render through the ONE shared
 * {@see WidgetApiErrorResponse::envelope()} builder. This is the module's
 * first controller; the ordering it sets is the pattern all later Widget
 * controllers copy.
 */
final class MaterializeWidgetManifestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_template.approve') === true;
    }

    /**
     * Permission denial renders as {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED}
     * (403) through the shared envelope builder -- never Laravel's default
     * 403 body -- and is reached BEFORE rules() validation, so it wins over
     * any request-shape failure.
     *
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        $envelope = WidgetApiErrorResponse::envelope(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to materialize Widget manifests.',
            (string) Str::uuid(),
        );

        throw new HttpResponseException(response()->json($envelope, $envelope['status']));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'widget_id' => ['required', 'integer', 'min:1'],
            'widget_theme_id' => ['required', 'integer', 'min:1'],
            'expected_source_fingerprint' => ['required', 'string'],
            'project_name' => ['prohibited'],
            'project' => ['prohibited'],
        ];
    }

    /**
     * Request-shape validation failures render through the shared frozen
     * envelope builder as {@see WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED}
     * (422) -- one of the three codes the Task 15a contract evolution added
     * to the enum (see that class's docblock), with the field-path
     * validation errors carried in the envelope's `details` member.
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $envelope = WidgetApiErrorResponse::envelope(
            WidgetApiErrorResponse::WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED,
            'The materialize request failed validation.',
            (string) Str::uuid(),
            $validator->errors()->toArray(),
        );

        throw new HttpResponseException(response()->json($envelope, $envelope['status']));
    }
}
