<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Models\Contact;
use App\Helpers\AppHelpers;
use Cms\Core\Events\AuditLogEvent;

class ContactObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Contact "creating" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function creating(Contact $model): void
    {
    }

    /**
     * Handle the Contact "created" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function created(Contact $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Contact "updating" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function updating(Contact $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Contact "updated" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function updated(Contact $model): void
    {
        //
    }

    /**
     * Handle the Contact "deleting" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function deleting(Contact $model): void
    {
    }

    /**
     * Handle the Contact "deleted" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function deleted(Contact $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Contact "restoring" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function restoring(Contact $model): void
    {
    }

    /**
     * Handle the Contact "restored" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function restored(Contact $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Contact "force deleted" event.
     *
     * @param Contact $model
     *
     * @return void
     */
    public function forceDeleted(Contact $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
