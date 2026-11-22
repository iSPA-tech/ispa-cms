<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models\Tracking;

use DateTimeInterface;
use Illuminate\Support\Str;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class ApiLog
 * About the ApiLog Entity
 *
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string request_id
 * @property string type - incoming, outgoing
 * @property string method - http method
 * @property string ip_address
 * @property string user_agent
 * @property string url
 * @property string domain
 * @property string data_request
 * @property string data_response
 * @property int user_id
 * @property int time_start
 * @property int duration
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 */
class ApiLog extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = Tables::API_LOGS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'request_id', 'type', 'method', 'ip_address', 'user_agent', 'url', 'domain', 'data_request', 'data_response', 'user_id',
        'time_start', 'duration',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'data_request' => 'json',
        'data_response' => 'json',
        'user_id' => 'integer',
        'time_start' => 'integer',
        'duration' => 'integer',
    ];

    /**
     * Generate UUID not exists in table
     *
     * @param string $uuid
     *
     * @return string
     * @author malayvuong
     * @since 6.19.6 - 2022-08-03, 23:06 ICT
     */
    public function gen_uuid(string $uuid): string
    {
        if ($this->where('request_id', $uuid)->select('id')->exists()) {
            return $this->gen_uuid(Str::uuid());
        }

        return $uuid;
    }
}
