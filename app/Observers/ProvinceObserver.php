<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Models\Province;
use App\Helpers\AppHelpers;
use Cms\Core\Events\AuditLogEvent;

class ProvinceObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Province "creating" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function creating(Province $model): void
    {
    }

    /**
     * Handle the Province "created" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function created(Province $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Province "updating" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function updating(Province $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Province "updated" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function updated(Province $model): void
    {
        //
    }

    /**
     * Handle the Province "deleting" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function deleting(Province $model): void
    {
    }

    /**
     * Handle the Province "deleted" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function deleted(Province $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Province "restoring" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function restoring(Province $model): void
    {
    }

    /**
     * Handle the Province "restored" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function restored(Province $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Province "force deleted" event.
     *
     * @param Province $model
     *
     * @return void
     */
    public function forceDeleted(Province $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
