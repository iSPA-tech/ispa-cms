<?php

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TrackingTargetCatalog extends Model
{
    use HasFactory;

    protected $table = Tables::TRACKING_TARGET_CATALOG;

    protected $fillable = [
        'target_key',
        'target_kind',
        'display_label',
        'public_path',
        'is_rankable',
    ];

    protected $casts = [
        'is_rankable' => 'boolean',
    ];
}
