<?php

declare(strict_types=1);

namespace Cms\Skeleton\Models;

use Cms\Core\Traits\HasUuid;
// @mode full
use Cms\Skeleton\Enums\SkeletonThingStatus;
// @endmode
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The sample entity of the Skeleton module. Rename it, add columns, or add more models beside it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $status draft | published
 */
final class SkeletonThing extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'skeleton_things';

    protected $fillable = ['uuid', 'name', 'description', 'status'];
    // @mode full

    protected $casts = ['status' => SkeletonThingStatus::class];
    // @endmode

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Rows whose name contains the text; `%` and `_` are matched as plain characters. */
    public function scopeNameContains(Builder $query, string $text): Builder
    {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';

        return $query->whereRaw("name like ? escape '!'", [$pattern]);
    }
}
