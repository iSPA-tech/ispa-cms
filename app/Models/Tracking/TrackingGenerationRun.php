<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;

class TrackingGenerationRun extends Model
{
    private const TERMINAL_STATUSES = ['completed', 'released', 'aborted'];

    protected $table = Tables::TRACKING_GENERATION_RUNS;

    protected $fillable = [
        'rotation_id',
        'active_rotation_key',
        'status',
        'closed_generation',
        'closed_mode',
        'first_ledger_id',
        'maximum_ledger_id',
        'next_held_generation',
        'barrier_started_at',
        'manifest_state',
        'manifest_id',
        'manifest_checksum',
        'completed_at',
    ];

    protected $casts = [
        'active_rotation_key' => 'integer',
        'closed_generation' => 'integer',
        'first_ledger_id' => 'integer',
        'maximum_ledger_id' => 'integer',
        'next_held_generation' => 'integer',
        'barrier_started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $run): void {
            $run->active_rotation_key = in_array($run->status, self::TERMINAL_STATUSES, true)
                ? null
                : 1;
        });
    }
}
