<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class EmailTemplate
 * About the EmailTemplate Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 6.62.6
 *
 * ==== Properties - Fields
 * @property integer id
 * @property string name
 * @property string group
 * @property string blade_layout
 * @property string key_name
 * @property string content
 * @property string variables
 * @property string sample_data
 * @property string description
 * @property integer language_id
 * @property integer user_id
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * @property-read \Cms\Core\Models\Language $language  Language associated with this template
 */
class EmailTemplate extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::EMAIL_TEMPLATES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'group', 'key_name', 'content', 'language_id', 'user_id',
        'blade_layout', 'variables', 'sample_data', 'description',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'language_id' => 'integer',
        'user_id' => 'integer',
        'variables' => 'array',
        'sample_data' => 'array',
    ];

    /**
     * Template language
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 7.160.1175 - 2025-08-25, 21:18 ICT
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id', 'id')->withDefault();
    }
}
