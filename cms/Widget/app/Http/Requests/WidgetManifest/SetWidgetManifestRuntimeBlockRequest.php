<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetManifest;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST|DELETE /api/v2/admin/widget/catalog/manifests/{manifest_hash}/runtime-block`
 * request shape (plan Chunk 2 Task 15c checklist item 1;
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * runtime-block route entries).
 *
 * ONE class drives BOTH the block (POST) and clear (DELETE) actions --
 * unlike Task 15b's Approve/Revoke pair, this task's allowed_paths lists
 * exactly one FormRequest file, and both verbs share an IDENTICAL frozen
 * payload `{expected_control_version, reason}` with no per-verb
 * human-message difference the way approve/revoke had. A shared
 * CONTROLLER base is still deliberately not introduced (see
 * {@see AbstractWidgetManifestApprovalRequest}'s own docblock, which
 * already names this task's different permission/axis as the reason).
 *
 * PERMISSION-BEFORE-VALIDATION (403-before-422 precedent copied verbatim
 * from {@see AbstractWidgetManifestApprovalRequest}): Laravel runs
 * authorize() before rules(), so a caller lacking
 * `widget_template.emergencyDisable` gets 403 regardless of body shape.
 * This is a SEPARATE permission from `widget_template.approve` -- an
 * eligibility approver holding only that permission is explicitly
 * insufficient here (checklist item 1; D-039 decision 4 in
 * adr-widget-project-authorization.md: merging the two permissions was
 * explicitly rejected because emergency runtime blocking can fail-soft
 * CURRENT active output, a materially different blast radius from
 * promotion-eligibility control).
 *
 * MANDATORY REASON, UNLIKE APPROVAL'S OPTIONAL ONE: `ApproveWidgetManifestRequest`'s
 * own docblock explicitly disclosed that "architecture.md's 'mandatory
 * reason' language is scoped to the separate emergency runtime-block
 * endpoints, not this eligibility-approval endpoint" -- confirming `reason`
 * must be `required` here. `expected_control_version` is likewise
 * required (the CAS precondition).
 *
 * CONTRACT EVOLUTION (third instance of the Task 15a/15b precedent --
 * "the hole was in the contract itself, closed when the first real
 * controller landed"): the Task 12 fixture froze this route's `statuses`
 * as `[200, 403, 404, 409, 503]` with NO 422 at all (unlike materialize/
 * approval, whose routes always declared 422 from Task 12 and only lacked
 * a matching CODE). Because `reason` is genuinely `required` here (see
 * above), a request-shape validation failure is unavoidable and must
 * render through this module's frozen envelope like every other Widget
 * controller -- leaving it as Laravel's raw, un-enveloped default 422
 * would violate "every response -- success or error -- uses this shape"
 * (docs/contracts/widget-site-layout-api.md "Envelope"). This task
 * therefore adds 422 to both runtime-block routes' `statuses` and mints
 * {@see WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED},
 * following the exact `WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED` /
 * `WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED` naming precedent. All four
 * contract surfaces (this enum addition, the fixture, the validator, and
 * docs/contracts/widget-site-layout-api.md) move together in this same
 * commit -- 26 codes total, still exact-set-checked in both directions.
 */
final class SetWidgetManifestRuntimeBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_template.emergencyDisable') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to change Widget manifest emergency runtime control.',
        ));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'expected_control_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3'],
        ];
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED,
            'The emergency runtime control request failed validation.',
            $validator->errors()->toArray(),
        ));
    }
}
