<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use App\Models\User;
use Cms\Core\Traits\HasUuid;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Traits\Models\StatusScope;
use App\Observers\StorageObserver;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Storage
 * About the Storage Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property string uuid
 * @property string uid
 * @property string code
 * @property string name
 * @property int enumerate_id
 * @property int parent_id
 * @property int established_by
 * @property string established_at
 * @property int user_id
 * @property int place_id
 * @property string status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 *
 * === Relationships
 * @property Enumerate storage_type - BelongsTo
 * @property Storage children - HasMany - Has many children
 * @property Storage parent - belongsTo
 * @property Upload image - morphOne - Get one image
 * @property Upload images - morphMany - Have many image
 * @property AuditLog logs - Logs of this item
 * @property User creator - User create this item
 * @property User author - User make this item
 *
 * @property-read \App\Models\User $author  User who established this storage
 * @property-read \Cms\Core\Models\Enumerate $storage_type  Storage type enumerate
 * @property-read \App\Models\User $creator  User who created this storage
 */
class Storage extends Model
{
    use HasFactory, HasUuid, SoftDeletes;
    use ModelBoot, StatusScope;

    /** @var string */
    public $table = CoreTableConstant::STORAGES;

    /** @var string */
    private static string $observer = StorageObserver::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid', 'uid', 'code', 'name', 'enumerate_id', 'established_at', 'established_by',
        'user_id', 'parent_id', 'status', 'place_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'enumerate_id' => 'integer',
        'established_by' => 'integer',
        'user_id' => 'integer',
        'parent_id' => 'integer',
        'place_id' => 'integer',
        'established_at' => 'date',
    ];

    /**
     * User Relationships
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'established_by', 'id');
    }

    /**
     * User Relationships
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function storage_type(): BelongsTo
    {
        return $this->belongsTo(Enumerate::class)->where('type', 'storage');
    }

    /**
     * Children relationship
     *
     * @return HasMany
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function children(): hasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }

    /**
     * User Relationships
     *
     * @return MorphMany
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function logs(): morphMany
    {
        return $this->morphMany(AuditLog::class, 'target');
    }

    /**
     * Parent relationship
     *
     * @return belongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function parent(): belongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id', 'id');
    }

    /**
     * User Relationships
     *
     * @return BelongsTo
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
