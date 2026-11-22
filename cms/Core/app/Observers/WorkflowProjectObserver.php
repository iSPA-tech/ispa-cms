<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use App\Helpers\AppHelpers;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Workflows\WorkflowProject;

/**
 * Observer for the WorkflowProject model handling audit logging and datetime processing.
 *
 * Automatically processes datetime fields on creation and update, and dispatches
 * AuditLogEvent for all major lifecycle events with change tracking on updates.
 *
 * Listens to: creating, created, updating, updated, deleting, deleted, restoring, restored, forceDeleted
 * Side effects: AuditLogEvent dispatch, datetime normalization on creating/updating
 *
 * @see \Cms\Core\Models\Workflows\WorkflowProject
 * @package Cms\Core\Observers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowProjectObserver
{
    /**
     * Normalize datetime fields according to the application timezone.
     *
     * @param WorkflowProject $model
     *
     * @return WorkflowProject
     *
     * @author malayvuong
     * @since 1.0.0
     */
    private function updateDateTime(WorkflowProject $model): WorkflowProject
    {
        //  Generate date time by timezone

        return $model;
    }

    /**
     * Handle the WorkflowProject "creating" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function creating(WorkflowProject $model): void
    {
        $this->updateDateTime($model);
    }

    /**
     * Handle the WorkflowProject "created" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function created(WorkflowProject $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the WorkflowProject "updating" event.
     *
     * Normalizes datetime fields, tracks attribute changes, and fires
     * an audit log event for non-restore updates.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function updating(WorkflowProject $model): void
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
     * Handle the WorkflowProject "updated" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function updated(WorkflowProject $model): void
    {
        //
    }

    /**
     * Handle the WorkflowProject "deleting" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function deleting(WorkflowProject $model): void
    {

    }

    /**
     * Handle the WorkflowProject "deleted" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function deleted(WorkflowProject $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the WorkflowProject "restoring" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function restoring(WorkflowProject $model): void
    {

    }

    /**
     * Handle the WorkflowProject "restored" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function restored(WorkflowProject $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the WorkflowProject "force deleted" event.
     *
     * @param WorkflowProject $model
     *
     * @return void
     */
    public function forceDeleted(WorkflowProject $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
