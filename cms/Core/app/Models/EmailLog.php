<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class EmailLog
 * About the EmailLog Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string uuid
 * @property string type
 * @property string subject
 * @property string to
 * @property string cc
 * @property string bcc
 * @property string blade
 * @property string content
 * @property string from_email
 * @property string from_name
 * @property string error_code
 * @property string error_message
 * @property string dedupe_key
 * @property string target_type
 * @property int target_id
 * @property int view
 * @property int email_id
 * @property int user_id
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 *
 * @property-read \Illuminate\Database\Eloquent\Model $target  Polymorphic target entity
 */
class EmailLog extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::EMAIL_LOGS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type', 'subject', 'to', 'cc', 'blade', 'content', 'view', 'email_id', 'user_id', 'bcc', 'uuid',
        'from_email', 'from_name', 'error_code', 'error_message', 'dedupe_key', 'target_type', 'target_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'content' => 'json',
        'view' => 'integer',
        'email_id' => 'integer',
        'user_id' => 'integer',
        'target_id' => 'integer',
    ];

    /**
     * Morph target
     *
     * @return MorphTo
     * @author malayvuong
     * @since 7.160.1175 - 2025-08-23, 12:00 ICT
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id');
    }
}
