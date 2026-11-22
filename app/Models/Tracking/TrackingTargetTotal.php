<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TrackingTargetTotal extends Model
{
    use HasFactory;

    protected $table = Tables::TRACKING_TARGET_TOTALS;

    protected $fillable = [
        'target_key',
        'target_type',
        'target_id',
        'baseline_views',
        'live_views',
        'baseline_scans',
        'live_scans',
        'baseline_quality',
        'scans_quality',
        'baseline_manifest_id',
        'baseline_manifest_checksum',
        'baseline_as_of',
        'accurate_from_date',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'baseline_views' => 'integer',
        'live_views' => 'integer',
        'baseline_scans' => 'integer',
        'live_scans' => 'integer',
        'baseline_as_of' => 'immutable_datetime',
        'accurate_from_date' => 'immutable_date',
    ];
}
