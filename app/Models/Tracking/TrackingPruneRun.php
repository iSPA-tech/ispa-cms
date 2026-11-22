<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;

class TrackingPruneRun extends Model
{
    protected $table = Tables::TRACKING_PRUNE_RUNS;

    protected $fillable = [
        'run_id',
        'cutoff_at',
        'freeze_max_id',
        'window_size',
        'status',
        'expected_rows',
        'expected_views',
        'current_window_start',
        'current_window_end',
        'last_processed_id',
        'deleted_rows',
        'deleted_views',
        'reconciliation_hash',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'cutoff_at' => 'immutable_datetime',
        'freeze_max_id' => 'integer',
        'window_size' => 'integer',
        'expected_rows' => 'integer',
        'expected_views' => 'integer',
        'current_window_start' => 'integer',
        'current_window_end' => 'integer',
        'last_processed_id' => 'integer',
        'deleted_rows' => 'integer',
        'deleted_views' => 'integer',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
