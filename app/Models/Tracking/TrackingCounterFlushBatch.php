<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;

class TrackingCounterFlushBatch extends Model
{
    protected $table = Tables::TRACKING_COUNTER_FLUSH_BATCHES;

    protected $fillable = [
        'bucket_id',
        'checksum',
        'status',
        'write_generation',
        'deltas',
        'target_count',
        'total_views',
        'applied_at',
    ];

    protected $casts = [
        'target_count' => 'integer',
        'total_views' => 'integer',
        'write_generation' => 'integer',
        'deltas' => 'array',
        'applied_at' => 'immutable_datetime',
    ];
}
