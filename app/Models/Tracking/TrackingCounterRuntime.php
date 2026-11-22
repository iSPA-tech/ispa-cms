<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class TrackingCounterRuntime extends Model
{
    public const DRIVER_RECEIPT = 'receipt';
    public const DRIVER_REDIS = 'redis';

    public const TRANSITION_STABLE = 'stable';
    public const TRANSITION_DRAINING_RECEIPTS = 'draining_receipts';
    public const TRANSITION_DRAINING_REDIS = 'draining_redis';

    protected $table = Tables::TRACKING_COUNTER_RUNTIME;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'active_driver',
        'transition_state',
        'epoch',
        'changed_at',
        'changed_by',
        'change_reason',
    ];

    protected $casts = [
        'id' => 'integer',
        'epoch' => 'integer',
        'changed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $runtime): void {
            $runtime->id ??= 1;
            if ((int) $runtime->id !== 1) {
                throw new LogicException('Tracking counter runtime supports only singleton id 1.');
            }
        });
    }
}
