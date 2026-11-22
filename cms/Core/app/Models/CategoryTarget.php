<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use Eloquent;
use Cms\Core\Traits\ModelBoot;
use Cms\Content\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class CategoryTarget
 * Pivot mapping categories to targets (posts, uploads, places...)
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 6.0.0
 *
 * @property int $category_id
 * @property string $target_type
 * @property int $target_id
 * @property bool $is_root
 * @property bool $is_leaf
 * @property string $created_at
 * @property string $updated_at
 * @property-read Post|null $post
 * @property-read MorphTo $target
 *
 * @mixin Eloquent
 */
class CategoryTarget extends Model
{
    use HasFactory;
    use ModelBoot;

    /** @var string */
    public $table = CoreTableConstant::CATEGORY_TARGET;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'category_id', 'target_type', 'target_id', 'is_root', 'is_leaf',
    ];

    /**
     * Get Posts target
     *
     * @return MorphTo
     * @author malayvuong
     * @since 6.62.6 - 2022-09-03, 00:08 ICT
     */
    public function post(): MorphTo
    {
        return $this->morphTo(Post::class, 'target_type', 'target_id');
    }

    /**
     * Morph to target
     *
     * @return MorphTo
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }
}
