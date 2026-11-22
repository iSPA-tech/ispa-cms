<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Models;

use Cms\Content\Constants\ContentTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Bookmark
 * About the Bookmark Entity
 *
 * @package Cms\Content\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int id
 * @property string target_type
 * @property int target_id
 * @property int user_id
 * @property string created_at
 * @property string updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Model $target  Morph relationship to the bookmarked entity
 *
 * @api Content public API; signature changes follow the module notes.
 */
class BookMark extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::BOOKMARK;

    protected $fillable = [
        'target_type', 'target_id', 'user_id',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Morph relationship
     *
     * @return MorphTo
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-18, 17:22 ICT
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }
}
