<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Models\Calendar;
use App\Helpers\AppHelpers;
use Cms\Core\Events\AuditLogEvent;
use App\Repositories\Eloquent\CalendarRepository;
use App\Traits\Observers\BeforeCreateTrait;
use App\Traits\Observers\BeforeUpdateTrait;

class CalendarObserver
{
    use BeforeCreateTrait, BeforeUpdateTrait;

    private function bumpCalendarCacheVersion(): void
    {
        CalendarRepository::bumpCalendarCacheVersion();
    }

    /**
     * @param Calendar $model
     *
     * @return Calendar
     */
    private function updateDateTime(Calendar $model): Calendar
    {
        $model->date_from = now()->parse(request('date_from', now()))->setTimezone(get_timezone())->format('Y-m-d H:i:s');
        if (request('is_all_day', false) === true) {
            $model->date_to = now()->parse(request('date_from', now()))->setTimezone(get_timezone())->endOfDay();
        } else {
            $model->date_to = now()->parse(request('date_to', now()))->setTimezone(get_timezone())->format('Y-m-d H:i:s');
        }

        return $model;
    }

    /**
     * Handle the Banner "creating" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function creating(Calendar $model): void
    {
        $this->updateDateTime($model);
    }

    /**
     * Handle the Calendar "created" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function created(Calendar $model): void
    {
        $this->bumpCalendarCacheVersion();
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Calendar "updating" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function updating(Calendar $model): void
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
     * Handle the Calendar "updated" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function updated(Calendar $model): void
    {
        $this->bumpCalendarCacheVersion();
    }

    /**
     * Handle the Calendar "deleting" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function deleting(Calendar $model): void
    {

    }

    /**
     * Handle the Calendar "deleted" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function deleted(Calendar $model): void
    {
        $this->bumpCalendarCacheVersion();
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Calendar "restoring" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function restoring(Calendar $model): void
    {

    }

    /**
     * Handle the Calendar "restored" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function restored(Calendar $model): void
    {
        $this->bumpCalendarCacheVersion();
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Calendar "force deleted" event.
     *
     * @param Calendar $model
     *
     * @return void
     */
    public function forceDeleted(Calendar $model): void
    {
        $this->bumpCalendarCacheVersion();
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
    }
}
