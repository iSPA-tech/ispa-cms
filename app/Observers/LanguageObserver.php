<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Helpers\AppHelpers;
use App\Support\LocaleManager;
use Cms\Core\Models\Language;
use Cms\Core\Events\AuditLogEvent;

class LanguageObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Language "creating" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function creating(Language $model): void
    {
    }

    /**
     * Handle the Language "created" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function created(Language $model): void
    {
        LocaleManager::forgetCache($model->path);
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Language "updating" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function updating(Language $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Language "updated" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function updated(Language $model): void
    {
        LocaleManager::forgetCache($model->path, $model->getOriginal('path'));
    }

    /**
     * Handle the Language "deleting" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function deleting(Language $model): void
    {
    }

    /**
     * Handle the Language "deleted" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function deleted(Language $model): void
    {
        LocaleManager::forgetCache($model->path, $model->getOriginal('path'));
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Language "restoring" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function restoring(Language $model): void
    {
    }

    /**
     * Handle the Language "restored" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function restored(Language $model): void
    {
        LocaleManager::forgetCache($model->path, $model->getOriginal('path'));
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Language "force deleted" event.
     *
     * @param Language $model
     *
     * @return void
     */
    public function forceDeleted(Language $model): void
    {
        LocaleManager::forgetCache($model->path, $model->getOriginal('path'));
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
