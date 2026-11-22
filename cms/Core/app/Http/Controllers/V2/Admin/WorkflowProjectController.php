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

        return $project;
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

        DB::beginTransaction();
        try {
            $model = $this->fill();

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

    protected function mapNodeTypeToStepType(string $nodeType, ?string $fallback): string
    {
        $allowed = [
            'event',
            'job',
            'call_workflow',
            'decision',
            'webhook',
            'ai_action',
            'script',
            'set_variable',
            'delay',
            'schedule_at',
            'approval',
            'loop',
            'parallel',
        ];

        // If UI sent a valid step_type, respect it
        if ($fallback && in_array($fallback, $allowed, true)) {
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
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function edit($id)
    {
        $id = (int)$id;

        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->model()
            ->with(['steps', 'connections.fromStep', 'connections.toStep'])
            ->find($id);

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
     * @param Integer $id
     * @param UpdateWorkflowProjectRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function update($id, UpdateWorkflowProjectRequest $request)
    {
        $id = (int)$id;

        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

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

        return ires()
            ->data($this->attachGraph($model))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.workflow_projects')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Integer $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function destroy($id)
    {
        $id = (int)$id;

        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

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
