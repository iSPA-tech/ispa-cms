<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;


use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class Role
 * About the Role Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * ==== Properties - Fields
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property array|null $custom Custom JSON configuration
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 * === Relationships
 * @property-read \Illuminate\Database\Eloquent\Collection|User[] $users
 * @property-read \Illuminate\Database\Eloquent\Collection|Permission[] $permissions
 *
 * @mixin \Eloquent
 */
class Role extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::ROLES;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'user_id', 'custom',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'custom' => 'json',
        'user_id' => 'integer',
    ];

    /**
     * Get users list
     *
     * @return BelongsToMany
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Permission relationship
     *
     * @return BelongsToMany
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function permissions(): belongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
