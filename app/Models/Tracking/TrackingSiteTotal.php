<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use LogicException;
use Illuminate\Database\Eloquent\Model;

class TrackingSiteTotal extends Model
{
    protected $table = Tables::TRACKING_SITE_TOTALS;

    public $incrementing = false;

    protected $fillable = [
        'id',
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
        'id' => 'integer',
        'baseline_views' => 'integer',
        'live_views' => 'integer',
        'baseline_scans' => 'integer',
        'live_scans' => 'integer',
        'baseline_as_of' => 'immutable_datetime',
        'accurate_from_date' => 'immutable_date',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $model): void {
            $model->id ??= 1;

            if ((int) $model->id !== 1) {
                throw new LogicException('Tracking site totals supports only singleton id 1.');
            }
        });
    }
}
