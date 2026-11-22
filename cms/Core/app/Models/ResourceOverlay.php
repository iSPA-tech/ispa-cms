<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Models;

use Cms\Core\Admin\Resources\SchemaCache;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Per-site overlay of one admin resource schema (roadmap D-10).
 *
 * @property int $id
 * @property string $resource_key
 * @property array<int, string>|null $hidden field names hidden from the schema
 * @property array<int, string>|null $order field names shown first, in this order
 * @property array<string, array<string, string>>|null $labels literal labels as {field: {locale: text}}
 * @property int|null $updated_by
 */
class ResourceOverlay extends Model
{
    protected $fillable = ['resource_key', 'hidden', 'order', 'labels', 'updated_by'];

    /**
     * Invalidate the cached schemas of the resource whenever its overlay is saved or deleted (also the previous
     * resource key when it changed). The token is replaced now and again after the surrounding transaction commits,
     * so a schema rebuilt from uncommitted-away data cannot outlive the write.
     */
    protected static function booted(): void
    {
        $bump = static function (self $overlay): void {
            $keys = array_unique(array_filter([
                (string) $overlay->resource_key,
                (string) $overlay->getOriginal('resource_key'),
            ]));
            $run = static function () use ($keys): void {
                foreach ($keys as $key) {
                    app(SchemaCache::class)->bumpOverlay($key);
                }
            };
            $run();
            DB::afterCommit($run);
        };

        static::saved($bump);
        static::deleted($bump);
    }

    /** Table name from the Core table constants. */
    public function getTable(): string
    {
        return CoreTableConstant::RESOURCE_OVERLAYS;
    }

    /** JSON columns are arrays. */
    protected function casts(): array
    {
        return ['hidden' => 'array', 'order' => 'array', 'labels' => 'array'];
    }

    /** The overlay row of a resource key, or null when the site has none (one query). */
    public static function forResource(string $key): ?self
    {
        return static::query()->where('resource_key', $key)->first();
    }
}
