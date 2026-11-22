<?php

declare(strict_types=1);

namespace Cms\Core\Models;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One application of a starter pack (key and version) to a site: who applied it and what each step did.
 */
class StarterPackApplication extends Model
{
    use HasUuid;

    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DRY_RUN = 'dry_run';

    protected $table = CoreTableConstant::STARTER_PACK_APPLICATIONS;

    protected $fillable = [
        'uuid',
        'pack_key',
        'pack_version',
        'status',
        'steps',
        'applied_by',
        'background_task_uuid',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'applied_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Limit the query to applications that finished successfully.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCEEDED);
    }
}
