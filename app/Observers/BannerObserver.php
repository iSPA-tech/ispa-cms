<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Models\Banner;
use App\Constants\Constant;
use App\Helpers\AppHelpers;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Support\Facades\DB;
use Cms\Core\Services\Cache\CacheVersionService;

class BannerObserver
{
    /**
     * Observer Auto Generate
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */

    /**
     * Handle the Banner "creating" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function creating(Banner $model): void
    {
    }

    /**
     * Handle the Banner "created" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function created(Banner $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
        $this->scheduleCacheInvalidation();
    }

    /**
     * Handle the Banner "updating" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function updating(Banner $model): void
    {
        if (!$model->isDirty()) {
            $this->scheduleCacheInvalidation();
        }

        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }

    /**
     * Handle the Banner "updated" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function updated(Banner $model): void
    {
        $this->scheduleCacheInvalidation();
    }

    /**
     * Handle the Banner "saved" event.
     *
     * A save with no attribute change (e.g. the admin only swapped the banner image,
     * which lives in the media pivot) fires no "updated" event, so the banner caches
     * would keep serving the old image. Bump them here for that case only; changed
     * saves are already covered by "created"/"updated".
     *
     * @param Banner $model
     *
     * @return void
     */
    public function saved(Banner $model): void
    {
        if (!$model->wasRecentlyCreated && !$model->wasChanged()) {
            $this->scheduleCacheInvalidation();
        }
    }

    /**
     * Handle the Banner "deleting" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function deleting(Banner $model): void
    {
    }

    /**
     * Handle the Banner "deleted" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function deleted(Banner $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
        $this->scheduleCacheInvalidation();
    }

    /**
     * Handle the Banner "restoring" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function restoring(Banner $model): void
    {
    }

    /**
     * Handle the Banner "restored" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function restored(Banner $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
        $this->scheduleCacheInvalidation();
    }

    /**
     * Handle the Banner "force deleted" event.
     *
     * @param Banner $model
     *
     * @return void
     */
    public function forceDeleted(Banner $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
        $this->scheduleCacheInvalidation();
    }

    private function scheduleCacheInvalidation(): void
    {
        DB::afterCommit(static fn() => app(CacheVersionService::class)->bump(Constant::CACHE_BY_BANNERS));
    }
}
