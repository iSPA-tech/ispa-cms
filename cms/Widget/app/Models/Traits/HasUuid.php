<?php

declare(strict_types=1);

namespace Cms\Widget\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * Manual UUID-generation convention for this codebase (plan Chunk 2 Task
 * 13a; adr-widget-instance-scope-schema.md context, citing
 * cms/AffiliateRecommendation/app/Models/Traits/HasUuid.php:10-27 as the
 * standing precedent).
 *
 * This codebase deliberately does NOT use Laravel's built-in `HasUuids`
 * trait for this purpose -- every new UUID-bearing Widget model
 * (WidgetInstance, WidgetLayout, WidgetPlacement) follows this identical
 * manual `bootHasUuid()` convention instead, copied into this module rather
 * than reused across modules (cms/Widget requires only "core";
 * cross-feature-module dependencies are out of bounds).
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(static function (Model $model): void {
            if (empty($model->{$model->getUuidKeyName()})) {
                $model->{$model->getUuidKeyName()} = RamseyUuid::uuid4()->toString();
            }
        });
    }

    public function getUuidKeyName(): string
    {
        return 'uuid';
    }

    public function scopeByUuid($query, string $uuid)
    {
        return $query->where('uuid', $uuid);
    }
}
