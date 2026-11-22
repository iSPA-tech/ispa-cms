<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetManifest;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared base for the approve/revoke eligibility-transition FormRequests
 * (plan Chunk 2 Task 15b). The two concrete requests are ~90% identical --
 * same `widget_template.approve` permission, same frozen payload
 * `{expected_attestation_version, reason}` (`reason` optional), same
 * 403-before-422 ordering copied from the Task 15a precedent
 * ({@see \Cms\Widget\Http\Requests\WidgetManifest\MaterializeWidgetManifestRequest}) --
 * differing only in the two human-readable message strings. This base owns
 * every identical piece; subclasses override exactly the two messages.
 *
 * PERMISSION-BEFORE-VALIDATION: Laravel runs authorize() BEFORE rules(), so
 * a caller lacking the permission gets 403 regardless of body shape, and
 * only a permitted caller's request ever reaches shape validation (422).
 * Both denials render through the shared {@see WidgetApiErrorResponse::jsonResponse()}
 * builder -- the one place a Widget error code becomes a response.
 *
 * NOTE: a separate CONTROLLER base is deliberately NOT extracted -- the
 * emergency runtime-block endpoints (Task 15c) use a different permission
 * (`widget_template.emergencyDisable`) and an independent `control_version`
 * axis, so a premature shared controller base would fit neither cleanly.
 */
abstract class AbstractWidgetManifestApprovalRequest extends FormRequest
{
    /**
     * Human-readable 403 message for this specific transition (approve vs
     * revoke); the code/status are always the shared
     * {@see WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED} (403).
     */
    abstract protected function permissionDeniedMessage(): string;

    /**
     * Human-readable 422 message for this specific transition; the
     * code/status are always the shared
     * {@see WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED}
     * (422).
     */
    abstract protected function validationFailedMessage(): string;

    public function authorize(): bool
    {
        return $this->user()?->can('widget_template.approve') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            $this->permissionDeniedMessage(),
        ));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'expected_attestation_version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
        ];
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED,
            $this->validationFailedMessage(),
            $validator->errors()->toArray(),
        ));
    }
}
