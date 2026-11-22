<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Helpers\AppHelpers;
use Cms\Product\Models\Brand;
use Cms\Core\Events\AuditLogEvent;

class BrandObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Brand "creating" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function creating(Brand $model): void
    {
    }

    /**
     * Handle the Brand "created" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function created(Brand $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Brand "updating" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function updating(Brand $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Brand "updated" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function updated(Brand $model): void
    {
        //
    }

    /**
     * Handle the Brand "deleting" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function deleting(Brand $model): void
    {
    }

    /**
     * Handle the Brand "deleted" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function deleted(Brand $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Brand "restoring" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function restoring(Brand $model): void
    {
    }

    /**
     * Handle the Brand "restored" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function restored(Brand $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Brand "force deleted" event.
     *
     * @param Brand $model
     *
     * @return void
     */
    public function forceDeleted(Brand $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
