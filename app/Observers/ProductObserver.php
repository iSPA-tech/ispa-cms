<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Observers;

use App\Enums\StatusEnum;
use Cms\Core\Events\AuditLogEvent;
use Cms\Product\Models\Product;
use App\Traits\Observers\BeforeCreateTrait;
use App\Traits\Observers\BeforeUpdateTrait;

class ProductObserver
{
    use BeforeCreateTrait, BeforeUpdateTrait;

    /**
     * Handle the Product "created" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function created(Product $model): void
    {
        event(new AuditLogEvent('create', $model->toArray(), get_class($model), $model->id, 'fa fa-plus-circle', 'success', auth()->id()));
    }

    /**
     * Handle the Product "updated" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function updated(Product $model): void
    {
        //
    }

    /**
     * Handle the Product "deleting" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function deleting(Product $model): void
    {
        $model->status = StatusEnum::DRAFT->value;
        $model->save();
    }

    /**
     * Handle the Product "deleted" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function deleted(Product $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('delete', [], get_class($model), $model->id, 'far fa-trash-alt', 'danger', auth()->id()));
    }

    /**
     * Handle the Product "restoring" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function restoring(Product $model): void
    {
    }

    /**
     * Handle the Product "restored" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function restored(Product $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('restore', [], get_class($model), $model->id, 'far fa-trash-undo-alt', 'info', auth()->id()));
    }

    /**
     * Handle the Product "force deleted" event.
     *
     * @param Product $model
     *
     * @return void
     */
    public function forceDeleted(Product $model): void
    {
        //  Save AuditLog
        event(new AuditLogEvent('forceDelete', $model->toArray(), get_class($model), $model->id, 'fa fa-minus-circle', 'danger', auth()->id()));
        // Remove the category target
        $model->categories()->detach();
    }
}
