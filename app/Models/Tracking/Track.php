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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Track
 * About the Track Entity
 *
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string tags
 * @property string target_type
 * @property int target_id
 * @property string ip_address
 * @property string agent
 * @property string url
 * @property string session_id
 * @property int view
 * @property string created_at
 * @property string updated_at
 *
 * === Relationships
 * @property MorphTo target
 */
class Track extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::TRACKS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tags', 'target_type', 'target_id', 'ip_address', 'agent', 'url',
        'session_id', 'view',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'tags' => 'json',
        'target_id' => 'integer',
        'view' => 'integer',
    ];

    /**
     * Morph to target
     *
     * @return MorphTo
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }
}
