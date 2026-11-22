<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v2/admin/widget/layouts/{uuid}/preview-sessions` request shape
 * (plan Chunk 2 Task 19; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * preview-sessions route entry: `request_fields: [overlay, locale,
 * template_context, target_type, target_id, viewport]`).
 *
 * SHAPE ONLY: required/type/enum checks for the request body. Every DOMAIN
 * check (registered Slot/Template/Target Context keys, target
 * authorization, resolvable persisted Instance Version, Manifest
 * eligibility) is {@see \Cms\Widget\Preview\WidgetPreviewSessionService}'s
 * own job -- identical SHAPE-vs-DOMAIN split established by
 * {@see UpdateWidgetLayoutDraftRequest} for the sibling Draft Save route;
 * `overlay.*` rules are deliberately IDENTICAL to that class's
 * `placements.*` rules (same normalized-overlay-row shape, per this task's
 * "validates overlay exactly like a draft save" contract).
 *
 * `target_type`/`target_id`/`template_context` are given together or not at
 * all. Without them the preview is untargeted (Unified Widget Catalog Part 2
 * Task 5): {@see \Cms\Widget\Preview\WidgetPreviewSessionService} binds the
 * home page, or a published sample page when the site has no home route.
 *
 * PERMISSION-BEFORE-VALIDATION: identical precedent to every other Widget
 * FormRequest in this module (403-before-422).
 */
final class PreviewWidgetLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('widget_layout.preview') === true;
    }

    /**
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(WidgetApiErrorResponse::jsonResponse(
            WidgetApiErrorResponse::WIDGET_PERMISSION_DENIED,
            'You do not have permission to preview this Widget site layout.',
        ));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'overlay' => ['present', 'array'],
            'overlay.*.slot_key' => ['required', 'string'],
            'overlay.*.scope_type' => ['required', 'string', Rule::in([
                WidgetPlacement::SCOPE_GLOBAL,
                WidgetPlacement::SCOPE_TEMPLATE,
                WidgetPlacement::SCOPE_TARGET,
            ])],
            'overlay.*.scope_key' => ['nullable', 'string', 'required_if:overlay.*.scope_type,' . WidgetPlacement::SCOPE_TEMPLATE],
            'overlay.*.target_type' => ['nullable', 'string', 'required_if:overlay.*.scope_type,' . WidgetPlacement::SCOPE_TARGET],
            'overlay.*.target_id' => ['nullable', 'string', 'required_if:overlay.*.scope_type,' . WidgetPlacement::SCOPE_TARGET],
            'overlay.*.widget_instance_uuid' => ['required', 'uuid'],
            'overlay.*.widget_instance_version' => ['required', 'integer', 'min:1'],
            'overlay.*.position' => ['required', 'integer', 'min:0'],
            'overlay.*.enabled' => ['required', 'boolean'],
            'overlay.*.rules' => ['present', 'array'],
            'locale' => ['required', 'string'],
            // No target (all three absent): the area editor's untargeted preview -- the home page,
            // or the first published page when the site has no home route (Part 2 Task 5).
            'template_context' => ['nullable', 'string', 'required_with:target_type,target_id'],
            'target_type' => ['nullable', 'string', 'required_with:target_id,template_context'],
            'target_id' => ['nullable', 'string', 'required_with:target_type'],
            'viewport' => ['nullable', 'string'],
            // Admin clickable-preview (editor) mode: an opaque message-pairing
            // token the minting admin chose; never a credential.
            'editor_nonce' => ['nullable', 'string', 'alpha_dash:ascii', 'max:64'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function overlay(): array
    {
        return (array) $this->input('overlay', []);
    }
}
