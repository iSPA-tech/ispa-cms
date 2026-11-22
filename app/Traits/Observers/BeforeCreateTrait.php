<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Traits\Observers;

use App\Enums\StatusEnum;
use Illuminate\Support\Carbon;

trait BeforeCreateTrait
{
    /**
     * Handle the model "creating" event.
     *
     * @param mixed $model
     * @return void
     */
    public static function creating(mixed $model): void
    {
        if (request()->has('published_at')) {
            $publishedAt = request('published_at');
            $model->published_at = blank($publishedAt)
                ? null
                : Carbon::parse($publishedAt, get_timezone())->setTimezone(get_timezone())->format('Y-m-d H:i:s');
        }

        if ($model->status === StatusEnum::PUBLISHED->value && is_null($model->published_at)) {
            $model->published_at = now();
        }
        // CPL: check if request(category_id) is array and have at lease one value, then set model category_id is the first item of array, else set null
//        if (in_array('category_id', $model->getFillable(), true)) {
//            $model->category_id = request('category_id') && is_array(request('category_id')) && count(request('category_id')) > 0
//                ? request('category_id')[0]
//                : null;
//        }
        //  Check if request has user_id and its > 0
        if (request('user_id', 0) > 0) {
            $model->user_id = request()->user_id;
        } else if (auth()->check()) {
            $model->user_id = auth()->id();
        }
    }
}
