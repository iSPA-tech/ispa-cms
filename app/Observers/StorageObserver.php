<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Helpers\AppHelpers;
use Cms\Core\Models\Storage;
use Cms\Core\Events\AuditLogEvent;

class StorageObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Storage "creating" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function creating(Storage $model): void
    {
    }

    /**
     * Handle the Storage "created" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function created(Storage $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Storage "updating" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function updating(Storage $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Storage "updated" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function updated(Storage $model): void
    {
        //
    }

    /**
     * Handle the Storage "deleting" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function deleting(Storage $model): void
    {
    }

    /**
     * Handle the Storage "deleted" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function deleted(Storage $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
        //  Process to delete children
        $model->children()->delete();
    }

    /**
     * Handle the Storage "restoring" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function restoring(Storage $model): void
    {
    }

    /**
     * Handle the Storage "restored" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function restored(Storage $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Storage "force deleted" event.
     *
     * @param Storage $model
     *
     * @return void
     */
    public function forceDeleted(Storage $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
