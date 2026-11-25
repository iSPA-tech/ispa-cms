<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Services\Workflow\WorkflowScheduleService;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectInactive;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotRunnable;

/**
 * Run an authored workflow project by hand, set its cron schedule and list its runs.
 *
 * Workflow projects have no `uuid` column, so they are addressed by id like
 * the rest of the workflow admin API. Run and schedule need the project
 * `update` ability; the run list needs `workflow_project.viewAny`
 * (the same checks as WorkflowProjectController). A manual run and a schedule change are audited (roadmap B5).
 *
 * @see WorkflowProjectRunService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   2026.11 (2026-11-24)
 */
class WorkflowRunController extends Controller
{
    /**
     * Start a run of the project acting for the caller (202 with the new instance).
     *
     * @param Request                   $request    The incoming request.
     * @param string                    $project    Workflow project id.
     * @param WorkflowProjectRunService $runService Run service.
     * @return AppResponse
     */
    public function run(Request $request, string $project, WorkflowProjectRunService $runService): AppResponse
    {
        $model = WorkflowProject::query()->find($project);
        if (! $model) {
            return ires()->notFound(__('mgs.model.404'));
        }

        // Starting a run by hand is its own ability (B8); drafting rights are not enough.
        if (! $request->user()?->can('run', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        try {
            $instance = $runService->start($model, $request->user(), [], 'manual');
            WorkflowAudit::project(WorkflowAudit::RUN_STARTED, $model, ['instance_id' => $instance->id], 'info', $this->actorId($request));
        } catch (WorkflowAlreadyRunning $e) {
            return ires()->error()->code(409)->errorCode($e::CODE)->message($e->getMessage());
        } catch (WorkflowProjectInactive|WorkflowProjectNotRunnable $e) {
            return ires()->error()->code(422)->errorCode($e::CODE)->message($e->getMessage());
        }

        return ires()->code(202)->data(['instance' => $this->serializeInstance($instance)]);
    }

    /**
     * Set or clear the project's cron schedule; scheduled runs act for the caller.
     *
     * Body: {cron: string|null, timezone: string|null}. A null or empty cron turns the schedule off.
     *
     * @param Request                   $request    The incoming request.
     * @param string                    $project    Workflow project id.
     * @param WorkflowProjectRunService $runService Run service (system-managed check).
     * @return AppResponse
     */
    public function schedule(Request $request, string $project, WorkflowProjectRunService $runService): AppResponse
    {
        $model = WorkflowProject::query()->find($project);
        if (! $model) {
            return ires()->notFound(__('mgs.model.404'));
        }

        if (! $request->user()?->can('update', $model)) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        // The schedule rule (system-managed refusal, cron/timezone checks, scheduler ownership, audit) is shared with
        // the setup screen.
        $schedules = app(WorkflowScheduleService::class);
        if ($problem = $schedules->problem($model, $request->input('cron'), $request->input('timezone'))) {
            return ires()->error()->code($problem['status'])->errorCode($problem['code'])->message($problem['message']);
        }

        $cron = $request->input('cron');
        $timezone = $request->input('timezone');
        $schedules->apply($model, is_string($cron) ? $cron : null, is_string($timezone) ? $timezone : null, $request->user());

        $model->load('scheduledBy');

        return ires()->data(['schedule' => $this->serializeSchedule($model)]);
    }

    /**
     * List the project's runs, newest first, paginated ({data, meta}).
     *
     * @param Request $request The incoming request (page, per_page).
     * @param string  $project Workflow project id.
     * @return AppResponse
     */
    public function instances(Request $request, string $project): AppResponse
    {
        if (! $request->user()?->can('workflow_project.viewAny')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $model = WorkflowProject::query()->find($project);
        if (! $model) {
            return ires()->notFound(__('mgs.model.404'));
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        $query = WorkflowInstance::query()->where('project_id', $model->id);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (WorkflowInstance $instance) => $this->serializeInstance($instance))
            ->values();

        return ires()->data([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'schedule' => $this->serializeSchedule($model->load('scheduledBy')),
        ]);
    }

    protected function actorId(Request $request): ?int
    {
        $id = $request->user()?->getKey();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeInstance(WorkflowInstance $instance): array
    {
        $trigger = (array) data_get($instance->context_snapshot, 'trigger', []);
        // Once the executor ran a step it stores the trigger as {event, at, meta}: the source is then in meta.
        $source = $trigger['source'] ?? data_get($trigger, 'meta.source')
            ?? (($trigger['event'] ?? null) === 'agentic.schedule.execute' ? 'agent_schedule' : null);

        return [
            'id' => $instance->id,
            'project_id' => $instance->project_id,
            'status' => $instance->status,
            'source' => $source,
            'started_at' => optional($instance->started_at)->toJSON(),
            'finished_at' => optional($instance->finished_at)->toJSON(),
            'duration_ms' => $instance->started_at && $instance->finished_at
                ? (int) $instance->started_at->diffInMilliseconds($instance->finished_at, true)
                : null,
            'created_at' => optional($instance->created_at)->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSchedule(WorkflowProject $project): array
    {
        $user = $project->scheduledBy;

        return [
            'cron' => $project->schedule_cron,
            'timezone' => $project->schedule_timezone,
            'scheduled_by' => $user ? ['uuid' => $user->uuid, 'name' => $user->name] : null,
            'last_scheduled_run_at' => optional($project->last_scheduled_run_at)->toJSON(),
            'is_active' => (bool) $project->is_active,
        ];
    }
}
