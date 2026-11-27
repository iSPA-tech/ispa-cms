<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Services\BlueprintDiscoveryService;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Workflows\Blueprint\BlueprintNormalizer;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Cms\Core\Workflows\Runtime\StepTypeCatalog;
use Cms\Core\Interfaces\WorkflowProjectInterface;
use Cms\Core\Http\Requests\WorkflowProject\StoreWorkflowProjectRequest;
use Cms\Core\Http\Requests\WorkflowProject\UpdateWorkflowProjectRequest;
use Cms\Core\Http\Requests\WorkflowProject\MultiDeleteWorkflowProjectRequest;

/**
 * CRUD operations for workflow projects with visual graph editing.
 *
 * Manages workflow project definitions, their steps (nodes), and connections
 * (edges). Converts between persisted step/connection records and the
 * Vue Flow graph format (nodes/edges) consumed by the admin UI.
 * Protects system-managed projects from manual mutation. Extends AdminController.
 *
 * @see WorkflowProjectInterface
 * @see BlueprintDiscoveryService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class WorkflowProjectController extends AdminController
{
    protected string $rulePrefix = '';

    /**
     * Controller constructor
     *
     * @param WorkflowProjectInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     * @added 2025-06-10 13:24:21
     */
    public function __construct(
        protected WorkflowProjectInterface $model
    )
    {
        $this->rulePrefix = str(Tables::WORKFLOW_PROJECT)->singular();

        parent::__construct();
    }

    /**
     * Get all function available for ui
     *
     * @param  BlueprintNormalizer $normalizer
     * @return JsonResponse|Response
     * @author malayvuong
     * @since 7.160.1175 - 2025-06-12, 19:06 ICT
     */
    public function functionList(BlueprintNormalizer $normalizer)
    {
        // Review fix 36: the list names the site's services and jobs; only workflow users see it.
        if (!user()?->can($this->rulePrefix . '.viewAny')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $data = (new BlueprintDiscoveryService())->scan();
        if (request()->boolean('compact')) {
            $data = $normalizer->normalize($data, true);
        }

        return ires()->data($data);
    }

    /**
     * Display a listing of the resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function index()
    {
        if (!user()?->can($this->rulePrefix . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'latest' => true,
        ]);

        $model = collect($model)->map(function ($item): array {
            return $this->attachGraph($item)->toArray();
        });

        return ires()->data($model);
    }

    /**
     * Convert persisted steps + connections to nodes/edges for UI.
     */
    protected function attachGraph($project)
    {
        if (!$project) {
            return $project;
        }

        $steps = $project->steps()
            //->withTrashed()
            ->get();
        $connections = $project->connections()
            ->with([
                'fromStep', // => fn($q) => $q->withTrashed(),
                'toStep', // => fn($q) => $q->withTrashed(),
            ])
            ->get();

        $project->setRelation('steps', $steps);
        $project->setRelation('connections', $connections);
        $project->system_managed = $this->isSystemManagedProject($project);

        $project->nodes = $steps->map(function ($step) {
            $id = $step->step_code ?: 'step-' . $step->id;
            $type = $step->step_config['node_type'] ?? $this->resolveNodeType($step);

            return [
                'id' => $id,
                'type' => $type,
                'position' => [
                    'x' => (int)($step->position_x ?? 0),
                    'y' => (int)($step->position_y ?? 0),
                ],
                'data' => [
                    'name' => $step->name,
                    'step_code' => $step->step_code,
                    'step_type' => $step->step_type,
                    'step_config' => $step->step_config,
                    'node_type' => $type,
                    'input_schema' => $step->input_schema,
                    'output_schema' => $step->output_schema,
                    'is_start' => (bool)$step->is_start,
                ],
            ];
        })->values()->toArray();

        $seenEdges = [];
        $project->edges = $connections->map(function ($conn) use (&$seenEdges) {
            $source = $conn->fromStep?->step_code;
            $target = $conn->toStep?->step_code;

            if (!$source || !$target) {
                return null;
            }

            $condition = $this->normalizePersistedConnectionCondition($conn->condition);
            $edgeKey = implode('|', [
                $source,
                $target,
                $condition['source_handle'] ?? '',
                $condition['target_handle'] ?? '',
                $condition['condition_value'] ?? ($condition['condition'] ?? ''),
            ]);

            if (isset($seenEdges[$edgeKey])) {
                return null;
            }
            $seenEdges[$edgeKey] = true;

            return [
                'id' => 'edge-' . $conn->id,
                'source' => $source,
                'target' => $target,
                'sourceHandle' => $condition['source_handle'] ?? null,
                'targetHandle' => $condition['target_handle'] ?? null,
                'data' => [
                    'condition' => $condition['condition'] ?? null,
                    'condition_type' => $condition['condition_type'] ?? null,
                    'condition_value' => $condition['condition_value'] ?? null,
                ],
            ];
        })->filter()->values()->toArray();

        $this->attachGuardState($project);

        return $project;
    }

    /**
     * Guardrail fields of a project row (B1/B3/B7/B8): published version, whether the draft differs from it, pause
     * state, waiting sensitive versions and what the caller may do.
     */
    protected function attachGuardState($project): void
    {
        $user = user();
        $project->can_publish = false;
        $project->can_run = false;
        $project->pending_versions = 0;
        $project->draft_differs = true;
        if (!Schema::hasTable((new WorkflowVersion())->getTable())) {
            return; // a schema without versions (minimal test schemas)
        }

        $project->can_publish = (bool) $user?->can(WorkflowPublishService::PUBLISH_ABILITY, $project);
        $project->can_run = (bool) $user?->can('run', $project);

        $publishedId = (int) ($project->published_version_id ?? 0);
        $project->published_version_id = $publishedId > 0 ? $publishedId : null;
        $project->published_version_number = $publishedId > 0
            ? WorkflowVersion::query()->whereKey($publishedId)->value('number')
            : null;
        $project->draft_differs = (bool) app(WorkflowPublishService::class)->draftStatus($project)['differs'];
        $project->paused_reason = $project->getAttribute('paused_reason');
        $project->paused_at = $project->getAttribute('paused_at');
        $project->pending_versions = WorkflowVersion::query()->where('project_id', $project->getKey())
            ->whereIn('status', WorkflowPublishService::WAITING_STATUSES)->count();
    }

    protected function resolveNodeType($step): string
    {
        if ($step->is_start) {
            return 'input';
        }

        return $step->step_config['node_type']
            ?? match ($step->step_type) {
                'decision' => 'decision',
                default => 'custom',
            };
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreWorkflowProjectRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function store(StoreWorkflowProjectRequest $request)
    {
        if (!user()?->can($this->rulePrefix . '.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($invalid = $this->validateGraph()) {
            return $invalid;
        }

        DB::beginTransaction();
        try {
            $model = $this->fill();
            // A new workflow is a draft: it can be turned on only after a version is published (B1).
            $model->is_active = false;

            $model->save();
            $this->syncGraph($model);
        } catch (Exception $e) {
            Log::debug('WorkflowProjectController@store: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.workflow_projects')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return Mixed $model
     *
     * @author malayvuong
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $fillable = $model->getFillable();
        $fillable = array_values(array_filter($fillable, function ($column) use ($model) {
            return Schema::hasColumn($model->getTable(), $column);
        }));

        $model->fill(request()->only($fillable));

        return $model;
    }

    /**
     * Sync nodes/edges from request into steps + connections tables.
     */
    protected function syncGraph($project): void
    {
        // If no graph payload is provided (e.g., metadata-only update), leave steps/connections untouched.
        if (!request()->has('nodes') && !request()->has('edges')) {
            return;
        }

        $nodes = collect(request()->input('nodes', []));
        $edges = collect(request()->input('edges', []));

        // Upsert steps
        $stepCodes = $nodes->map(function ($node) {
            return $node['data']['step_code'] ?? $node['id'] ?? null;
        })->filter()->values();

        // Remove stale steps
        if ($stepCodes->count()) {
            $project->steps()->whereNotIn('step_code', $stepCodes)->delete();
        } else {
            $project->steps()->delete();
        }

        foreach ($nodes as $node) {
            $stepCode = $node['data']['step_code'] ?? $node['id'] ?? null;
            if (!$stepCode) {
                continue;
            }

            $data = $node['data'] ?? [];
            $position = $node['position'] ?? [];
            $nodeType = $node['type'] ?? ($data['node_type'] ?? 'custom');
            $stepType = $this->mapNodeTypeToStepType($nodeType, $data['step_type'] ?? null);

            $project->steps()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'step_code' => $stepCode,
                ],
                [
                    'name' => $data['name'] ?? $stepCode,
                    'step_type' => $stepType,
                    'step_config' => array_merge(
                        ['node_type' => $nodeType],
                        $data['step_config'] ?? ($data['config'] ?? [])
                    ),
                    'input_schema' => $data['input_schema'] ?? null,
                    'output_schema' => $data['output_schema'] ?? null,
                    'position_x' => $position['x'] ?? 0,
                    'position_y' => $position['y'] ?? 0,
                    'is_start' => ($nodeType === 'input') || ($data['is_start'] ?? false),
                ]
            );
        }

        // Refresh step map
        $steps = $project->steps()->get()->keyBy('step_code');
        $idToStepCode = $nodes->mapWithKeys(function ($node) {
            $id = $node['id'] ?? null;
            $code = $node['data']['step_code'] ?? $id;
            return $id ? [$id => $code] : [];
        });

        // Rebuild connections
        $project->connections()->delete();
        foreach ($edges as $edge) {
            $fromCode = $edge['source'] ?? null;
            $toCode = $edge['target'] ?? null;

            // Map edge endpoints to canonical step codes when node IDs differ from step codes
            $fromCode = $idToStepCode[$fromCode] ?? $fromCode;
            $toCode = $idToStepCode[$toCode] ?? $toCode;

            if (!$fromCode || !$toCode || !$steps->has($fromCode) || !$steps->has($toCode)) {
                continue;
            }

            $project->connections()->create([
                'from_step_id' => $steps[$fromCode]->id,
                'to_step_id' => $steps[$toCode]->id,
                'condition' => $this->normalizeEdgeConditionPayload($edge),
            ]);
        }
    }

    /**
     * @param array<string, mixed>|mixed $condition
     * @return array<string, mixed>
     */
    protected function normalizePersistedConnectionCondition(mixed $condition): array
    {
        $condition = is_array($condition) ? $condition : [];

        $sourceHandle = $condition['source_handle'] ?? null;
        $conditionType = $condition['condition_type'] ?? null;
        $conditionValue = $condition['condition_value'] ?? null;
        $label = $condition['condition'] ?? null;

        if (! is_string($sourceHandle) || trim($sourceHandle) === '') {
            $sourceHandle = null;
        } else {
            $sourceHandle = trim($sourceHandle);
        }

        if (! is_string($conditionType) || trim($conditionType) === '') {
            $conditionType = null;
        } else {
            $conditionType = trim($conditionType);
        }

        if (! is_string($conditionValue) || trim($conditionValue) === '') {
            $conditionValue = null;
        } else {
            $conditionValue = trim($conditionValue);
        }

        if ($conditionType === null && $sourceHandle !== null) {
            $conditionType = 'equals';
            $conditionValue = $conditionValue ?? $sourceHandle;
        }

        if (! is_string($label) || trim($label) === '') {
            $label = $conditionValue;
        } else {
            $label = trim($label);
        }

        if (($condition['source_handle'] ?? null) === null && $conditionType === 'equals' && $conditionValue !== null) {
            $sourceHandle = $conditionValue;
        }

        return [
            'condition' => $label,
            'source_handle' => $sourceHandle,
            'target_handle' => $condition['target_handle'] ?? null,
            'condition_type' => $conditionType,
            'condition_value' => $conditionValue,
        ];
    }

    /**
     * @param array<string, mixed> $edge
     * @return array<string, mixed>
     */
    protected function normalizeEdgeConditionPayload(array $edge): array
    {
        $edgeData = is_array($edge['data'] ?? null) ? $edge['data'] : [];
        $condition = $this->normalizePersistedConnectionCondition([
            'condition' => $edgeData['condition'] ?? null,
            'source_handle' => $edge['sourceHandle'] ?? null,
            'target_handle' => $edge['targetHandle'] ?? null,
            'condition_type' => $edgeData['condition_type'] ?? null,
            'condition_value' => $edgeData['condition_value'] ?? null,
        ]);

        if (($condition['condition_type'] ?? null) === null && ($condition['condition'] ?? null) !== null) {
            $condition['condition_type'] = 'equals';
            $condition['condition_value'] = $condition['source_handle'] ?? $condition['condition'];
        }

        return $condition;
    }

    /**
     * Refuse a graph payload before anything is written: a node whose `step_type` no runner or legacy value knows
     * (422 listing the step codes), a step that calls a class or model the workflow allowlist refuses
     * (core.workflow_security, the same message the runner gives at run time), or a node the catalog refuses
     * (NodeConfigValidator: schema, `_v`, node permission, taint/approval lint; ApprovalAssignment: unknown assignee
     * uuids, an unreadable `sla`) or an approval step switched to auto-publish before its JEV calibration is ready
     * (`auto_approval_not_ready`). Every problem is reported at once:
     * `message` joins them with `; ` and `data.errors` lists `{step_code, code, message}`.
     *
     * @return mixed|null Error response, or null when the graph may be saved.
     */
    protected function validateGraph(mixed $project = null)
    {
        if (!request()->has('nodes')) {
            return null;
        }

        $nodes = request()->input('nodes', []);
        if (!is_array($nodes)) {
            return ires()->error()->code(422)->message('nodes must be a list.');
        }

        $problems = app(WorkflowPublishPolicy::class)->draftProblems($this->requestGraph($nodes), user(), $project instanceof \Cms\Core\Models\Workflows\WorkflowProject ? $project : null);
        if ($problems === []) {
            return null;
        }

        $unknown = array_column(array_filter($problems, static fn (array $p): bool => $p['code'] === 'unknown_type'), 'step_code');
        $messages = $unknown !== [] ? ['Unknown step type for steps: ' . implode(', ', $unknown) . '.'] : [];
        foreach ($problems as $problem) {
            if ($problem['code'] !== 'unknown_type') {
                $messages[] = $problem['message'];
            }
        }

        return ires()->error()->code(422)
            ->data(['errors' => $problems])
            ->message(implode('; ', $messages));
    }

    /**
     * The request's nodes/edges in the policy's graph shape (step codes; the step type a save would store, or the
     * type the UI sent when it is not a storable one, so the policy reports it).
     *
     * @param array<int|string, mixed> $nodes
     * @return array{steps: list<array<string, mixed>>, connections: list<array{from: string, to: string}>}
     */
    protected function requestGraph(array $nodes): array
    {
        $steps = [];
        foreach ($nodes as $node) {
            $node = is_array($node) ? $node : [];
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $stepCode = $data['step_code'] ?? $node['id'] ?? null;
            if (!$stepCode) {
                continue;
            }

            $sentType = $data['step_type'] ?? null;
            $nodeType = $node['type'] ?? ($data['node_type'] ?? 'custom');
            $config = $data['step_config'] ?? ($data['config'] ?? []);

            $steps[] = [
                'step_code' => (string) $stepCode,
                'step_type' => $sentType !== null ? $sentType : $this->mapNodeTypeToStepType(is_string($nodeType) ? $nodeType : 'custom', null),
                'step_config' => is_array($config) ? $config : [],
                'is_start' => ($nodeType === 'input') || (bool) ($data['is_start'] ?? false),
            ];
        }

        return ['steps' => $steps, 'connections' => $this->graphEdgesForLint()];
    }

    /**
     * Edges of the request graph as step codes (`[{from, to}]`) for the taint lint.
     *
     * @return list<array{from: string, to: string}>
     */
    protected function graphEdgesForLint(): array
    {
        $nodes = request()->input('nodes', []);
        $edges = request()->input('edges', []);
        if (!is_array($nodes) || !is_array($edges)) {
            return [];
        }

        $idToCode = [];
        foreach ($nodes as $node) {
            if (is_array($node) && is_scalar($node['id'] ?? null)) {
                $idToCode[(string) $node['id']] = (string) ($node['data']['step_code'] ?? $node['id']);
            }
        }

        $result = [];
        foreach ($edges as $edge) {
            $from = is_array($edge) && is_scalar($edge['source'] ?? null) ? (string) $edge['source'] : null;
            $to = is_array($edge) && is_scalar($edge['target'] ?? null) ? (string) $edge['target'] : null;
            if ($from !== null && $to !== null) {
                // The output handle decides whether an approval guards the edge (WorkflowPublishPolicy::edges()).
                $result[] = ['from' => $idToCode[$from] ?? $from, 'to' => $idToCode[$to] ?? $to, 'condition' => $this->normalizeEdgeConditionPayload($edge)];
            }
        }

        return $result;
    }

    protected function mapNodeTypeToStepType(string $nodeType, ?string $fallback): string
    {
        // If the UI sent a step type a runner (or a legacy value) knows, keep it as is.
        if ($fallback && StepTypeCatalog::isStorable($fallback)) {
            return $fallback;
        }

        return match ($nodeType) {
            'input' => 'event',
            'decision' => 'decision',
            'output' => 'script',
            default => 'script',
        };
    }

    /**
     * Display the specified resource.
     *
     * @param Integer $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function show($id)
    {
        $id = (int)$id;

        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        return ires();
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param string $uuid Workflow uuid (UUID-first admin API)
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function edit($uuid)
    {
        $model = $this->model->model()
            ->with(['steps', 'connections.fromStep', 'connections.toStep'])
            ->where('uuid', (string) $uuid)
            ->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($this->attachGraph($model));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param string $uuid Workflow uuid (UUID-first admin API)
     * @param UpdateWorkflowProjectRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function update($uuid, UpdateWorkflowProjectRequest $request)
    {
        $model = $this->model->model()->where('uuid', (string) $uuid)->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        // Model checks must use the bare policy ability: a dotted key with a model argument never reaches the policy
        // (Gate::before defers to policies, which have no `workflow_project.update` method) and always denied outside `local`.
        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($guardResponse = $this->guardSystemManagedProjectMutation($model)) {
            return $guardResponse;
        }

        if ($invalid = $this->validateGraph($model)) {
            return $invalid;
        }

        // Turning a workflow on needs a published version: runs never execute the draft (B1).
        $wasActive = (bool) $model->is_active;
        if (request()->boolean('is_active') && !$wasActive) {
            try {
                app(WorkflowPublishService::class)->assertCanActivate($model);
            } catch (WorkflowProjectNotPublished $e) {
                return ires()->error()->code(422)->errorCode(WorkflowPublishService::NOT_PUBLISHED_CODE)->message($e->getMessage());
            }
        }

        DB::beginTransaction();
        try {
            $model = $this->fill($model);

            if (!$model->save()) {
                DB::rollBack();
                return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.workflow_projects')]));
            }

            $this->syncGraph($model);

            // Reload fresh relations so we return the latest node positions/edges
            $model->load([
                'steps',
                'connections',
                'connections.fromStep',
                'connections.toStep',
            ]);
        } catch (Exception $e) {
            Log::debug('WorkflowProjectController@update: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        if ((bool) $model->is_active !== $wasActive) {
            try {
                app(WorkflowChangeAlerter::class)->notify('activation_changed', $model, ['active' => (bool) $model->is_active], user());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ires()
            ->data($this->attachGraph($model))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.workflow_projects')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param string $uuid Workflow uuid (UUID-first admin API)
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function destroy($uuid)
    {
        $model = $this->model->model()->where('uuid', (string) $uuid)->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($guardResponse = $this->guardSystemManagedProjectMutation($model)) {
            return $guardResponse;
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.failed.delete', ['name' => __('nav.a.workflow_projects')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.workflow_projects')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteWorkflowProjectRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteWorkflowProjectRequest $request)
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.workflow_projects')]));
    }

    protected function isSystemManagedProject($project): bool
    {
        $tags = $project?->tags;
        if (!is_array($tags)) {
            return false;
        }

        return in_array('system-managed', $tags, true) || in_array('schedule-wrapper', $tags, true);
    }

    protected function guardSystemManagedProjectMutation($project)
    {
        if (!$this->isSystemManagedProject($project)) {
            return null;
        }

        return ires()->error()->message('System-managed workflow wrappers can not be modified here.');
    }
}
