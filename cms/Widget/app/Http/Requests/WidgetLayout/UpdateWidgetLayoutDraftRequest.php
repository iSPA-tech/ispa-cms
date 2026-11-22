<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Requests\WidgetLayout;

use Cms\Widget\Http\Support\WidgetApiErrorResponse;
use Cms\Widget\Models\WidgetPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v2/admin/widget/layouts/{uuid}/draft` request shape (plan Chunk
 * 2 Task 17; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * draft route entry: `request_fields: [expected_current_draft_id,
 * expected_lock_version, placements]`).
 *
 * SHAPE ONLY: required/type/enum checks for the request body. Every DOMAIN
 * check (registered Slot/Template/Target Context keys, target authorization,
 * cardinality, empty `rules`, resolvable Instance Version) is
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService}'s own job --
 * mirroring {@see \Cms\Widget\Http\Requests\WidgetInstance\StoreWidgetInstanceRequest}'s
 * established SHAPE-vs-DOMAIN split. A shape failure renders Laravel's
 * DEFAULT JSON validation response (422, `{message, errors}`), NOT the
 * Widget envelope -- identical disclosed reasoning to that class: the
 * frozen 26-code dictionary has no code fitting a malformed Draft document
 * and this task must add no new code.
 *
 * `placements.*.scope_key`/`target_type`/`target_id` are conditionally
 * required by `scope_type` via `required_if` (Laravel's wildcard sibling
 * alignment: `required_if:placements.*.scope_type,...` compares against the
 * SAME array index, not a single scalar sibling) -- this is still SHAPE
 * validation ("a target-scoped placement must carry a target_id"), not the
 * DOMAIN check of whether that target/key is actually registered or
 * authorized.
 *
 * PERMISSION-BEFORE-VALIDATION: identical precedent to every other Widget
 * FormRequest in this module.
 */
final class UpdateWidgetLayoutDraftRequest extends FormRequest
{
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
            'You do not have permission to update this Widget site layout draft.',
        ));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'expected_current_draft_id' => ['required', 'integer', 'min:1'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'placements' => ['present', 'array'],
            'placements.*.slot_key' => ['required', 'string'],
            'placements.*.scope_type' => ['required', 'string', Rule::in([
                WidgetPlacement::SCOPE_GLOBAL,
                WidgetPlacement::SCOPE_TEMPLATE,
                WidgetPlacement::SCOPE_TARGET,
            ])],
            'placements.*.scope_key' => ['nullable', 'string', 'required_if:placements.*.scope_type,' . WidgetPlacement::SCOPE_TEMPLATE],
            'placements.*.target_type' => ['nullable', 'string', 'required_if:placements.*.scope_type,' . WidgetPlacement::SCOPE_TARGET],
            'placements.*.target_id' => ['nullable', 'string', 'required_if:placements.*.scope_type,' . WidgetPlacement::SCOPE_TARGET],
            'placements.*.widget_instance_uuid' => ['required', 'uuid'],
            'placements.*.widget_instance_version' => ['required', 'integer', 'min:1'],
            'placements.*.position' => ['required', 'integer', 'min:0'],
            'placements.*.enabled' => ['required', 'boolean'],
            // 'present' not 'required': Laravel's required rule treats an
            // empty array as "missing" (count() < 1), but rules = {} is the
            // MVP-valid, expected value (architecture.md: "MVP rejects
            // rules != {} with 422") -- 'present' only checks the key
            // exists, regardless of value, matching the top-level
            // `placements` field's own reasoning just above.
            'placements.*.rules' => ['present', 'array'],
        ];
    }

    public function expectedCurrentDraftId(): int
    {
        return (int) $this->input('expected_current_draft_id');
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->input('expected_lock_version');
    }

    /**
     * @return list<array{
     *     slot_key: string,
     *     scope_type: string,
     *     scope_key: string|null,
     *     target_type: string|null,
     *     target_id: string|null,
     *     widget_instance_uuid: string,
     *     widget_instance_version: int,
     *     position: int,
     *     enabled: bool,
     *     rules: array<string, mixed>
     * }>
     */
    public function placements(): array
    {
        return (array) $this->input('placements', []);
    }
}
