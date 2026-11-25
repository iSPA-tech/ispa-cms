<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Versioning\WorkflowAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Stops a project's schedule when someone other than its scheduler changes what the schedule would run.
 *
 * Scheduled runs act for `scheduled_by_user_id`. When a different user (or a process with no signed-in user) creates,
 * changes, deletes or restores a step or connection of the project, or turns the project on, the schedule is cleared
 * (cron, timezone and scheduler), so it runs again only after someone who owns the new content saves it.
 *
 * Registered on WorkflowStep, WorkflowConnection and WorkflowProject by CoreServiceProvider.
 *
 * @package Cms\Core\Observers
 * @author  iSPA Team
 * @since   2026.11 (2026-11-23)
 */
class WorkflowScheduleOwnershipObserver
{
    /**
     * Schedule columns cleared when the scheduler no longer owns the project content.
     */
    protected const CLEARED = [
        'schedule_cron' => null,
        'schedule_timezone' => null,
        'scheduled_by_user_id' => null,
    ];

    public function created(Model $model): void
    {
        $this->graphChanged($model);
    }

    public function updated(Model $model): void
    {
        $this->graphChanged($model);
    }

    public function deleted(Model $model): void
    {
        $this->graphChanged($model);
    }

    public function restored(Model $model): void
    {
        $this->graphChanged($model);
    }

    /**
     * Turning a project on is a change of what runs: only the scheduler keeps the schedule.
     */
    public function updating(Model $model): void
    {
        if (! $model instanceof WorkflowProject || ! $model->isDirty('is_active') || ! $model->is_active) {
            return;
        }

        if (! $this->hasScheduleColumns()) {
            return;
        }

        $scheduler = $model->scheduled_by_user_id;
        if ($scheduler !== null && (int) $scheduler !== (int) auth()->id()) {
            $model->forceFill(self::CLEARED);
        }
    }

    /**
     * Clear the owning project's schedule when the change comes from someone other than its scheduler.
     */
    protected function graphChanged(Model $model): void
    {
        if (! $model instanceof WorkflowStep && ! $model instanceof WorkflowConnection) {
            return;
        }

        $projectId = $model->getAttribute('project_id');
        $actor = auth()->id();

        // Change control (review fix 14): the signed-in user becomes a draft editor of the project.
        if ($projectId !== null) {
            WorkflowAuthors::recordDraftEdit((int) $projectId, is_numeric($actor) ? (int) $actor : null);
        }

        if ($projectId === null || ! $this->hasScheduleColumns()) {
            return;
        }

        // One conditional update: no event loop, and the scheduler's own edits leave the schedule alone.
        WorkflowProject::query()
            ->whereKey($projectId)
            ->whereNotNull('scheduled_by_user_id')
            ->when($actor !== null, fn ($query) => $query->where('scheduled_by_user_id', '!=', $actor))
            ->update(self::CLEARED);
    }

    /**
     * Whether the schedule columns exist (a schema without the schedule migration has nothing to clear). Not cached:
     * graph saves are rare admin actions, and a cached answer could outlive a migration.
     */
    protected function hasScheduleColumns(): bool
    {
        return Schema::hasColumn((new WorkflowProject())->getTable(), 'scheduled_by_user_id');
    }
}
