<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models\Tracking;

use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Tracking Top
 * About the TrackingTop Entity
 *
 * @author malayvuong
 * @since 7.160.1175
 *
 * @property int id
 * @property string range_name
 * @property string range_start
 * @property string range_end
 * @property string target_type
 * @property integer target_id
 * @property integer total_views
 * @property integer rank
 * @property string created_at
 * @property string updated_at
 *
 */
class TrackingTop extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::TRACKING_TOP;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'range_name', 'range_start', 'range_end', 'target_key', 'target_type', 'target_id', 'total_views', 'rank',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'range_start' => 'date',
        'range_end' => 'date',
        'target_id' => 'integer',
        'total_views' => 'integer',
        'rank' => 'integer',
    ];
}
