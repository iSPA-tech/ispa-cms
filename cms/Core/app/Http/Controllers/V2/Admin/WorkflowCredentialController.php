<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialTester;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Workflow credentials (`api/v2/admin/workflow/credentials`), all behind `workflow_credential.manage`.
 *
 * Secrets go in and never come out: list/create/update answer with WorkflowCredentialStore::describe() (name, type,
 * which fields are set, the `$bind:` value to paste in a workflow), never a payload value. Test runs one harmless read
 * call against the type's fixed host.
 *
 * Guardrails (roadmap B5/B6): create, update and delete need a step-up grant `workflow_credential` (route middleware)
 * and are audited with names and field names only; a test is audited with its result. Changing or deleting a
 * credential that an active workflow binds sends the `credential_changed` alert.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @since   2026-11-24
 */
class WorkflowCredentialController extends Controller
{
    public const ABILITY = 'workflow_credential.manage';

    public function __construct(
        private readonly WorkflowCredentialStore $store,
        private readonly WorkflowChangeAlerter $alerter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->allowed($request)) {
            return $this->forbidden();
        }

        $items = WorkflowCredential::query()->orderBy('name')->get()
            ->map(fn (WorkflowCredential $c): array => $this->store->describe($c))->all();

        return response()->json([
            'data' => $items,
            'meta' => ['types' => array_map(static fn (array $fields): array => array_keys($fields), WorkflowCredential::FIELDS)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->allowed($request)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:' . WorkflowCredentialStore::NAME_PATTERN, Rule::unique(WorkflowCredential::TABLE, 'name')],
            'type' => ['required', 'string', Rule::in(WorkflowCredential::types())],
            'description' => ['nullable', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'payload.*' => ['nullable', $this->scalarRule()],
        ]);

        $credential = $this->store->create($data, $this->userId($request));
        $this->audit(WorkflowAudit::CREDENTIAL_CREATED, $credential, [
            'name' => $credential->name,
            'type' => $credential->type,
            'fields_set' => array_keys(array_filter($credential->fieldsSet())),
        ], 'success', $request);

        return response()->json(['data' => $this->store->describe($credential)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        if (!$this->allowed($request)) {
            return $this->forbidden();
        }

        $credential = WorkflowCredential::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64', 'regex:' . WorkflowCredentialStore::NAME_PATTERN,
                Rule::unique(WorkflowCredential::TABLE, 'name')->ignore($credential->getKey())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payload' => ['sometimes', 'array'],
            'payload.*' => ['nullable', $this->scalarRule()],
        ]);

        $before = ['name' => $credential->name, 'description' => $credential->description];
        $fieldsChanged = array_keys(array_filter((array) ($data['payload'] ?? []), static fn ($v): bool => $v !== null && $v !== ''));
        $credential = $this->store->update($credential, $data, $this->userId($request));
        $after = ['name' => $credential->name, 'description' => $credential->description];

        $this->audit(WorkflowAudit::CREDENTIAL_UPDATED, $credential, [
            'from' => array_diff_assoc($before, $after),
            'to' => array_diff_assoc($after, $before),
            'secret_fields_changed' => $fieldsChanged,
        ], 'warning', $request);
        if ($fieldsChanged !== [] || $before['name'] !== $after['name']) {
            $this->alertUsers($before['name'], $request, $before['name'] !== $after['name'] ? 'renamed' : 'changed');
        }

        return response()->json(['data' => $this->store->describe($credential)]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        if (!$this->allowed($request)) {
            return $this->forbidden();
        }

        $credential = WorkflowCredential::query()->where('uuid', $uuid)->firstOrFail();
        $this->alertUsers($credential->name, $request, 'deleted');
        $credential->delete();
        $this->audit(WorkflowAudit::CREDENTIAL_DELETED, $credential, ['name' => $credential->name, 'type' => $credential->type], 'danger', $request);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function test(Request $request, string $uuid, WorkflowCredentialTester $tester): JsonResponse
    {
        if (!$this->allowed($request)) {
            return $this->forbidden();
        }

        $credential = WorkflowCredential::query()->where('uuid', $uuid)->firstOrFail();
        $result = $tester->test($credential);
        $this->audit(WorkflowAudit::CREDENTIAL_TESTED, $credential, ['name' => $credential->name, 'status' => $result['status'] ?? null], 'info', $request);

        return response()->json(['data' => $result]);
    }

    /**
     * A payload field is a plain value (string or number) of at most 4096 characters.
     */
    private function scalarRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!is_scalar($value) || mb_strlen((string) $value) > 4096) {
                $fail(__('text.workflow_credential.error.field_invalid'));
            }
        };
    }

    /**
     * @param array<string, mixed> $changed Names and field names only, never a payload value.
     */
    private function audit(string $action, WorkflowCredential $credential, array $changed, string $color, Request $request): void
    {
        WorkflowAudit::log($action, $changed, WorkflowCredential::class, (int) $credential->getKey(), $color, $this->userId($request), 'fa fa-key');
    }

    /**
     * Alert the publishers once per active workflow that binds the credential (at most 10 alerts).
     */
    private function alertUsers(string $name, Request $request, string $reason): void
    {
        $projects = $this->store->activeProjectsUsing($name);
        foreach (array_slice($projects, 0, 10) as $project) {
            $this->alerter->notify('credential_changed', $project, [
                'credential' => $name,
                'reason' => $reason,
                'projects' => count($projects),
            ], $request->user());
        }
    }

    private function allowed(Request $request): bool
    {
        return (bool) $request->user()?->can(self::ABILITY);
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getKey();

        return is_numeric($id) ? (int) $id : null;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
