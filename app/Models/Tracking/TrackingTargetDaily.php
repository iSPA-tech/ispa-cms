<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TrackingTargetDaily extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'target_key',
        'target_type',
        'target_id',
        'total_views',
        'total_sessions',
        'total_visitors',
        'total_scans',
        'total_downloads',
        'aggregate_version',
    ];

    protected $casts = [
        'date' => 'date',
        'target_id' => 'integer',
        'total_views' => 'integer',
        'total_sessions' => 'integer',
        'total_visitors' => 'integer',
        'total_scans' => 'integer',
        'total_downloads' => 'integer',
        'aggregate_version' => 'integer',
    ];

    public function getTable(): string
    {
        return (string) config('tracking.canonical_target_daily_table', Tables::TRACKING_TARGET_DAILY);
    }
}
