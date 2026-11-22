<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Models\Gallery;
use Cms\Core\Events\AuditLogEvent;
use App\Traits\Observers\BeforeUpdateTrait;
use App\Traits\Observers\BeforeCreateTrait;

class GalleryObserver
{
    use BeforeCreateTrait, BeforeUpdateTrait;

    /**
     * Handle the Gallery "created" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function created(Gallery $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Gallery "updated" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function updated(Gallery $model): void
    {
        //
    }

    /**
     * Handle the Gallery "deleting" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function deleting(Gallery $model): void
    {
    }

    /**
     * Handle the Gallery "deleted" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function deleted(Gallery $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
        //  Delete category target
        $model->target()->delete();
    }

    /**
     * Handle the Gallery "restoring" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function restoring(Gallery $model): void
    {
    }

    /**
     * Handle the Gallery "restored" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function restored(Gallery $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Gallery "force deleted" event.
     *
     * @param Gallery $model
     *
     * @return void
     */
    public function forceDeleted(Gallery $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
