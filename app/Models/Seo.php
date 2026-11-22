<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use DateTimeInterface;
use App\Constants\Tables;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Class Seo
 * About the Seo Entity
 *
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string target_type
 * @property int target_id
 * @property int language_id
 * @property string title
 * @property string description
 * @property string hashtag
 * @property string facebook
 * @property string twitter
 * @property string custom
 * @property string config
 * @property string created_at
 * @property string updated_at
 * @property morphTo target
 */
class Seo extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::SEOS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'target_type', 'target_id', 'language_id', 'title', 'description',
        'hashtag', 'facebook', 'twitter', 'custom', 'config',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'facebook' => 'json',
        'twitter' => 'json',
        'custom' => 'json',
        'config' => 'json',
        'target_id' => 'integer',
        'language_id' => 'integer',
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
