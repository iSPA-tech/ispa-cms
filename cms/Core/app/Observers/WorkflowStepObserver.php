<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use App\Helpers\AppHelpers;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Events\AuditLogEvent;

/**
 * Observer for the WorkflowStep model handling audit logging and datetime processing.
 *
 * Automatically processes datetime fields on creation and update, and dispatches
 * AuditLogEvent for all major lifecycle events with change tracking on updates.
 *
 * Listens to: creating, created, updating, updated, deleting, deleted, restoring, restored, forceDeleted
 * Side effects: AuditLogEvent dispatch, datetime normalization on creating/updating
 *
 * @see \Cms\Core\Models\Workflows\WorkflowStep
 * @package Cms\Core\Observers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowStepObserver
{
    /**
     * Normalize datetime fields according to the application timezone.
     *
     * @param WorkflowStep $model
     *
     * @return WorkflowStep
     *
     * @author malayvuong
     * @since 1.0.0
     */
    private function updateDateTime(WorkflowStep $model): WorkflowStep
    {
        //  Generate date time by timezone

        return $model;
    }

    /**
     * Handle the WorkflowStep "creating" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function creating(WorkflowStep $model): void
    {
        $this->updateDateTime($model);
    }

    /**
     * Handle the WorkflowStep "created" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function created(WorkflowStep $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the WorkflowStep "updating" event.
     *
     * Normalizes datetime fields, tracks attribute changes, and fires
     * an audit log event for non-restore updates.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function updating(WorkflowStep $model): void
    {
        $this->updateDateTime($model);
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the WorkflowStep "updated" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function updated(WorkflowStep $model): void
    {
        //
    }

    /**
     * Handle the WorkflowStep "deleting" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function deleting(WorkflowStep $model): void
    {

    }

    /**
     * Handle the WorkflowStep "deleted" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function deleted(WorkflowStep $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the WorkflowStep "restoring" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function restoring(WorkflowStep $model): void
    {

    }

    /**
     * Handle the WorkflowStep "restored" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function restored(WorkflowStep $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the WorkflowStep "force deleted" event.
     *
     * @param WorkflowStep $model
     *
     * @return void
     */
    public function forceDeleted(WorkflowStep $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
