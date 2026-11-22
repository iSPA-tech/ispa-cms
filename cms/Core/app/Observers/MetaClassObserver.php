<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use App\Helpers\AppHelpers;
use Cms\Core\Models\MetaClass;
use Cms\Core\Events\AuditLogEvent;

/**
 * Observer for the MetaClass model handling audit logging and auto-population.
 *
 * Automatically sets the user_id on creation and dispatches AuditLogEvent
 * for all major lifecycle events with change tracking on updates.
 *
 * Listens to: creating, created, updating, updated, deleting, deleted, restoring, restored, forceDeleted
 * Side effects: AuditLogEvent dispatch, user_id auto-population on creating
 *
 * @see \Cms\Core\Models\MetaClass
 * @package Cms\Core\Observers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaClassObserver
{
    /**
     * Handle the MetaClass "creating" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function creating(MetaClass $model): void
    {
        $model->user_id = user('id');
    }

    /**
     * Handle the MetaClass "created" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function created(MetaClass $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the MetaClass "updating" event.
     *
     * Tracks attribute changes and fires an audit log event for non-restore updates.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function updating(MetaClass $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the MetaClass "updated" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function updated(MetaClass $model): void
    {

    }

    /**
     * Handle the MetaClass "deleting" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function deleting(MetaClass $model): void
    {

    }

    /**
     * Handle the MetaClass "deleted" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function deleted(MetaClass $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the MetaClass "restoring" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function restoring(MetaClass $model): void
    {

    }

    /**
     * Handle the MetaClass "restored" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function restored(MetaClass $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the MetaClass "force deleted" event.
     *
     * @param MetaClass $model
     *
     * @return void
     */
    public function forceDeleted(MetaClass $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
