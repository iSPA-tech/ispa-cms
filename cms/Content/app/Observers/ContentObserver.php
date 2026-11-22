<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Observers;

use App\Helpers\AppHelpers;
use Cms\Content\Models\Content;
use Cms\Core\Events\AuditLogEvent;
use App\Services\AdminUpdateContentService;

/**
 * Audits Content lifecycle events and keeps the content audios in sync.
 */
class ContentObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Content "creating" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function creating(Content $model): void
    {
    }

    /**
     * Handle the Content "created" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function created(Content $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
        //  Update content audio
        if (!empty(request()?->input('audios', []))) {
            $service = new AdminUpdateContentService();
            $service->syncAudios($model, request()?->input('audios'));
        }
    }

    /**
     * Handle the Content "updating" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function updating(Content $model): void
    {
        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Content "updated" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function updated(Content $model): void
    {
        //  Update content audio
        if (!empty(request()?->input('audios', []))) {
            $service = new AdminUpdateContentService();
            $service->syncAudios($model, request()?->input('audios'));
        }
    }

    /**
     * Handle the Content "deleting" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function deleting(Content $model): void
    {
    }

    /**
     * Handle the Content "deleted" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function deleted(Content $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));

        //  Delete audio
        $model->audios()->delete();
    }

    /**
     * Handle the Content "restoring" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function restoring(Content $model): void
    {
    }

    /**
     * Handle the Content "restored" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function restored(Content $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Content "force deleted" event.
     *
     * @param Content $model
     *
     * @return void
     */
    public function forceDeleted(Content $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
