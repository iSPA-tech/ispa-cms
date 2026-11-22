<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use LogicException;
use Illuminate\Database\Eloquent\Model;

class TrackingRuntimeState extends Model
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_DUAL = 'dual';
    public const MODE_AGGREGATE = 'aggregate';

    public const PROCESSING_OPEN = 'open';
    public const PROCESSING_HELD = 'held';

    protected $table = Tables::TRACKING_RUNTIME_STATE;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'write_generation',
        'mode',
        'processing_state',
        'direct_counter_owner',
        'first_direct_write_date',
        'generation_opened_at',
        'changed_by',
        'change_reason',
    ];

    protected $casts = [
        'id' => 'integer',
        'write_generation' => 'integer',
        'first_direct_write_date' => 'immutable_date',
        'generation_opened_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $model): void {
            $model->id ??= 1;

            if ((int) $model->id !== 1) {
                throw new LogicException('Tracking runtime state supports only singleton id 1.');
            }
        });
    }
}
