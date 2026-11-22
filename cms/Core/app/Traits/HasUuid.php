<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits;

use Illuminate\Support\Str;

/**
 * Trait HasUuid
 *
 * Ensures models always have a UUID value:
 * - Auto-generates UUID on model creation if missing.
 * - Backfills UUID on retrieval if the record was saved without one,
 *   writing it directly to the database without triggering events or updating timestamps.
 *
 * @package Cms\Core\Traits
 */
trait HasUuid
{
    /**
     * Boot the HasUuid trait.
     *
     * Registers model event listeners for `creating` and `retrieved`.
     *
     * @return void
     */
    public static function bootHasUuid(): void
    {
        // Auto-generate UUID when creating a new record
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // Backfill missing UUID when a record is retrieved from the database
        static::retrieved(function ($model) {
            // Skip if uuid column was not selected or does not exist on this record
            if (!array_key_exists('uuid', $model->getAttributes())) {
                return;
            }

            // Skip if UUID already present
            if (!empty($model->uuid)) {
                return;
            }

            $uuid = (string) Str::uuid();
            $model->uuid = $uuid;

            // Direct DB update: no events, no timestamp changes, no observer triggers
            $model->newModelQuery()
                ->where($model->getKeyName(), $model->getKey())
                ->update(['uuid' => $uuid]);
        });
    }
}
