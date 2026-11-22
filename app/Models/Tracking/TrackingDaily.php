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
 * Class Tracking Daily
 * About the Tracking Daily Entity
 *
 * @author malayvuong
 * @since 7.160.1175
 *
 * @property int id
 * @property int views
 * @property int visitors
 * @property int place_scan
 * @property int item_scan
 * @property int scans
 * @property int new_register
 * @property int login
 * @property int logout
 * @property int subscribe
 * @property int unsubscribe
 * @property int new_order
 * @property int income
 * @property int comments
 * @property int download
 * @property int sessions
 * @property int ip_address
 * @property int payments
 * @property string date
 * @property string created_at
 * @property string updated_at
 *
 */
class TrackingDaily extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::TRACKING_DAILY;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'views', 'visitors', 'place_scan', 'item_scan', 'scans', 'new_register', 'login', 'logout', 'subscribe',
        'unsubscribe', 'new_order', 'income', 'comments', 'download', 'sessions', 'payments', 'date',
        'is_closed', 'aggregate_version', 'aggregated_at', 'last_applied_at', 'late_event_count',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'views' => 'integer',
        'visitors' => 'integer',
        'place_scan' => 'integer',
        'item_scan' => 'integer',
        'scans' => 'integer',
        'sessions' => 'integer',
        'ip_address' => 'integer',

        'new_register' => 'integer',
        'login' => 'integer',
        'logout' => 'integer',
        'subscribe' => 'integer',
        'unsubscribe' => 'integer',
        'new_order' => 'integer',
        'income' => 'integer',
        'comments' => 'integer',
        'download' => 'integer',
        'payments' => 'integer',
        'date' => 'date',
        'is_closed' => 'boolean',
        'aggregate_version' => 'integer',
        'aggregated_at' => 'immutable_datetime',
        'last_applied_at' => 'immutable_datetime',
        'late_event_count' => 'integer',
    ];
}
