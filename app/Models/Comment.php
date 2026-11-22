<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use App\Constants\Tables;
use Cms\Core\Models\Enumerate;
use Cms\Core\Models\AbstractBaseModel;
use Cms\Core\Traits\Models\SerializeDateTrait;
use Cms\Core\Traits\Models\StatusScope;
use Cms\Core\Traits\Models\HasMediaTrait;
use Cms\Core\Traits\Models\ResolvesAppendedRelations;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Comment
 * About the Comment Entity
 *
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string name
 * @property string subject
 * @property string content
 * @property string target_type
 * @property int target_id
 * @property int vote
 * @property string status
 * @property int reply_to
 * @property int contact_id
 * @property int user_id
 * @property string last_view
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 * @property Contact contact - BelongsTo
 * @property Enumerate comment_status - BelongsTo
 */
class Comment extends AbstractBaseModel
{
    use HasFactory, SoftDeletes, HasMediaTrait;
    use ResolvesAppendedRelations;
    use SerializeDateTrait, StatusScope;

    /** @var string */
    public $table = Tables::COMMENTS;

    /** @var string[] $appends */
    protected $appends = ['image'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'subject', 'content', 'target_type', 'target_id', 'vote', 'status', 'reply_to', 'contact_id', 'user_id', 'last_view',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'target_id' => 'integer',
        'vote' => 'integer',
        'reply_to' => 'integer',
        'contact_id' => 'integer',
        'user_id' => 'integer',
        'last_view' => 'datetime',
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

    /**
     * Contact relationship
     *
     * @return BelongsTo
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-02-10, 11:1
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Status relationship
     *
     * @return BelongsTo
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function comment_status(): BelongsTo
    {
        return $this->belongsTo(Enumerate::class, 'status', 'enum_key');
    }
}
