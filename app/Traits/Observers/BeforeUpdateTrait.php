<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Traits\Observers;

use App\Enums\StatusEnum;
use App\Helpers\AppHelpers;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Support\Carbon;

trait BeforeUpdateTrait
{
    /**
     * Handle the model "updating" event.
     *
     * @param mixed $model
     *
     * @return void
     */
    public static function updating(mixed $model): void
    {
        if (request()->has('published_at')) {
            $publishedAt = request('published_at');
            $model->published_at = blank($publishedAt)
                ? null
                : Carbon::parse($publishedAt, get_timezone())->setTimezone(get_timezone())->format('Y-m-d H:i:s');
        }

        if ($model->isDirty('status')) {
            if ($model->status === StatusEnum::PUBLISHED->value && is_null($model->published_at)) {
                $model->published_at = now();
            }
        }
        // CPL: check if request(category_id) is array and have at lease one value, then set model category_id is the first item of array, else set null
        if (in_array('category_id', $model->getFillable(), true)) {
//            $model->category_id = request('category_id') && is_array(request('category_id')) && count(request('category_id')) > 0
//                ? request('category_id')[0]
//                : null;
            //  Remove cache tag by category
        }

        //  Get changed data
        $changed = AppHelpers::getChanges($model);
        //  Fire AuditLog
        if (!isset($changed['from']['deleted_at'])) {
            event(new AuditLogEvent('update', $changed, get_class($model), $model->id, 'fa fa-edit', 'primary', auth()->id()));
        }
    }
}
