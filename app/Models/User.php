<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use JsonException;
use App\Constants\Tables;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Cms\Core\Traits\HasUuid;
use Cms\Core\Traits\ModelBoot;
use Cms\Core\Models\Role;
use Cms\Core\Models\Tag;
use Cms\Core\Models\Upload;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\AuthChallenge;
use Cms\Core\Models\MetaData;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\Enumerate;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Models\UserSetting;
use Cms\Core\Notifications\QueuedVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Cms\Core\Observers\UserObserver;
use Cms\Core\Services\CMSPermissionKeyResolver;
use Cms\Core\Services\MetaAssignmentService;
use App\Services\AdminUpdateContentService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class User
 * About the User Entity
 *
 * @author malayvuong
 * @since 1.0.0
 * ==== Properties - Fields
 * @property int id
 * @property string uuid
 * @property string username
 * @property string name
 * @property string email
 * @property string email_verified_at
 * @property string mobile
 * @property string password Encrypted Password
 * @property string remember_token
 * @property int type
 * @property int level
 * @property int parent_id
 * @property string status
 * @property string created_at
 * @property string updated_at
 * @property string deleted_at
 * @property-read array permissions
 * @property-read array parsed_settings
 * === Relationships
 * @property Upload avatar - morphOne - Avatar image
 * @property User parent - belongsTo - Belong to user
 * @property User children - HasMany - Has many users
 * @property Role[] roles - BelongsToMany - Has many role
 * @property AuditLog auditLog - HasMany - Has many audit logs
 * @property UserSetting settings - HasMany - Has many settings
 */
class User extends Authenticatable implements CanResetPasswordContract, MustVerifyEmailContract, PasskeyUser
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes, CanResetPassword, MustVerifyEmail, PasskeyAuthenticatable;
    use HasUuid, ModelBoot;

    /** @var string */
    private static string $observer = UserObserver::class;
    /** @var string */
    protected $table = Tables::USERS;
    /** @var array|null Cached permission keys */
    private ?array $cachedPermissionKeys = null;
    /** @var string[] $appends */
    protected $appends = [
        'parsed_settings',
    ];

    /**
     * Queue the signed verification email only after account data commits.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify((new QueuedVerifyEmail())->afterCommit());
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'saint_name', 'diocese', 'email', 'password', 'status', 'username', 'email_verified_at', 'mobile', 'type', 'level', 'parent_id',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'parent_id' => 'integer',
        'type' => 'integer',
        'email_verified_at' => 'datetime',
    ];

    /**
     * Set meta data value by key
     *
     * @throws JsonException
     * @since 7.160.1175 - 2024-09-14, 10:04 ICT
     * @author malayvuong
     */
    public function setMetaData(string $key, mixed $value = ''): void
    {
        $meta = MetaClass::query()->where('target', 'User')->with('options')->first();
        if (!$meta) {
            return;
        }
        //  Get the metadata attributes by place
        $metaData = $meta->options->where('name', $key)->first();
        //  Fill only meta attributes that have value
        if (!$metaData) {
            return;
        }
        $attribute = [
            'class_id' => $meta->id,
            'type' => $metaData->type,
            'id' => $metaData->id,
            'value' => $value,
        ];
        if (empty($attribute['value'])) {
            return;
        }

        //  Update/Create only if metadata is not empty
        $list = [];
        $valueId = $attribute['value']['id'] ?? null;
        if (!is_array($attribute['value'])) {
            $valueId = app(MetaAssignmentService::class)->resolveValue($attribute['type'], $attribute['value'])?->getKey();
        } elseif (in_array($attribute['type'] ?? '', ['text', 'string', 'date', 'url'])) {
            $valueId = optional((new AdminUpdateContentService())->setMetaValue($attribute, $this, ($attribute['type'] ?? '') === 'date', $key))->id;
        }

        if (!$valueId) {
            return;
        }

        //  After create ValueID, then create or update it into meta()
        $list[] = [
            'meta_class_id' => $attribute['class_id'],
            'meta_attribute_id' => $attribute['id'],
            'value_type' => get_value_class($attribute['type']),
            'value_id' => $valueId,
        ];

        $linked = $this->meta()->where([
            'meta_class_id' => $attribute['class_id'],
            'meta_attribute_id' => $attribute['id'],
            'value_id' => $valueId,
            'value_type' => get_value_class($attribute['type']),
        ])->first();

        if ($linked) {
            $linked->update($list[0]);
        } else {
            $this->meta()->create($list[0]);
        }

    }

    /**
     * Place meta data relationships
     *
     * @return MorphMany
     * @author malayvuong
     * @since 6.62.6 - 2023-01-28, 11:06 ICT
     */
    public function meta(): MorphMany
    {
        return $this->morphMany(MetaData::class, 'target');
    }

    /**
     * Assign role into this user
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-14, 09:59 ICT
     */
    public function assignRole(int $role_id): void
    {
        if ($role_id > 0 && $this->roles()->where('role_id', $role_id)->exists()) {
            $this->roles()->attach($role_id);
        }
    }

    /**
     * Role relationship
     *
     * @return BelongsToMany
     * @author malayvuong
     * @since 1.0.0
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** User tags of this user (shared `tags` table, type `user`, paired through `tags_data`; pivot `source`). */
    public function tags(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(Tag::class, 'target', 'tags_data', 'target_id', 'tag_id')
            ->where('tags.type', Tag::TYPE_USER)->withPivot('source')->withTimestamps();
    }

    /**
     * Get all parent categories
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-11, 08:37 ICT
     */
    public function getParentsAttribute()
    {
        $parents = collect();

        $parent = $this->parent;

        while (!is_null($parent)) {
            $parents->prepend($parent);
            $parent = $parent->parent;
        }

        return $parents;
    }

    /**
     * Get parsed settings attribute
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-01-27, 17:01 ICT
     */
    public function getParsedSettingsAttribute(): object
    {
        $settings = (object)[];

        foreach ($this->settings as $setting) {
            $settings->{$setting->setting_key} = $setting->setting_value;
        }

        return $settings;
    }

    /**
     * User settings relationship
     *
     * @return HasMany
     * @author vuongnm
     * @since 6.62.6 - 2023-01-09, 10:45 ICT
     */
    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class, 'user_id', 'id');
    }

    public function mfaFactors(): HasMany
    {
        return $this->hasMany(UserMfaFactor::class, 'user_id', 'id');
    }

    public function authChallenges(): HasMany
    {
        return $this->hasMany(AuthChallenge::class, 'user_id', 'id');
    }

    /**
     * Slug relationship
     *
     * @return MorphOne
     * @author malayvuong
     * @since  1.0.0
     */
    public function avatar(): morphOne
    {
        return $this->morphOne(Upload::class, 'target')
            ->where('category', 'avatar')->orderBy('main', 'DESC');
    }

    /**
     * Check is webmaster
     *
     * @return bool
     * @author malayvuong
     * @since 1.0.0
     * @last_updated 6.19.6 - 2022-05-31, 21:33 ICT - by malayvuong
     * - Change rule of webmaster
     */
    public function isWebmaster(): bool
    {
        return $this->id === 1 || $this->roleLevel() === 0;
    }

    /**
     * get Role level
     *
     * @return int
     * @author malayvuong
     * @since 1.0.0 - 2021-12-10, 13:13
     */
    public function roleLevel(): int
    {
        $level = 99;
        foreach ($this->roles as $role) {
            $roleLevel = (int) data_get($role->custom, 'level', 99);

            if ($roleLevel < $level) {
                $level = $roleLevel;
            }
        }

        return $level;
    }

    /**
     * Audit Logs relationships
     *
     * @return morphMany
     * @author malayvuong
     * @since 1.0.0
     */
    public function auditLogs(): morphMany
    {
        return $this->morphMany(AuditLog::class, 'target');
    }

    /**
     * Parent relationship
     *
     * @return BelongsTo
     * @author malayvuong
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id', 'id');
    }

    /**
     * Children Relationship
     *
     * @return HasMany
     * @author malayvuong
     */
    public function children(): HasMany
    {
        return $this->hasMany(__CLASS__, 'id', 'parent_id');
    }

    /**
     * Get all permission keys from user's roles (cached).
     *
     * Returns an array of permission keys in "group.name" format.
     * Results are cached for the lifetime of the model instance.
     *
     * @return array<string>
     */
    public function permissionKeys(): array
    {
        if ($this->cachedPermissionKeys !== null) {
            return $this->cachedPermissionKeys;
        }

        $keys = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $keys[] = trim((string) $permission->group) . '.' . trim((string) $permission->name);
            }
        }

        $this->cachedPermissionKeys = array_unique($keys);
        return $this->cachedPermissionKeys;
    }

    /**
     * Check if user has a permission using DB permissions with resolver fallback.
     *
     * This method checks the permission against the user's DB permissions,
     * using CMSPermissionKeyResolver to handle both new module-prefixed
     * and legacy permission formats.
     *
     * @param string $key The permission key to check
     * @return bool
     */
    public function hasPermissionResolved(string $key): bool
    {
        if (empty($key)) {
            return false;
        }

        $candidates = CMSPermissionKeyResolver::getCandidates($key);
        $userPermissions = $this->permissionKeys();

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check User Role/Permission
     *
     * Supports both new module-prefixed keys and legacy keys with fallback:
     * - New format: "module.resource.ability" (e.g., "education.research-period.delete")
     * - Legacy format: "resource.ability" (e.g., "research-period.delete")
     *
     * When checking a new format permission, it will also check the legacy
     * format as a fallback for backward compatibility.
     *
     * @param string|null $key The permission key to check
     *
     * @return bool
     * @author malayvuong
     * @since 1.0.0
     * @updated 8.0.0 - Added module prefix fallback support
     * @updated 8.1.0 - Refactored to use hasPermissionResolved() for unified authorization
     */
    public function hasRole(string $key = null): bool
    {
        return $key ? $this->hasPermissionResolved($key) : false;
    }

    /**
     * Get permissions array list
     *
     * @return array
     * @author malayvuong
     * @since 6.19.6 - 2022-06-16, 23:46 ICT
     */
    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = trim((string) $permission->group) . '.' . trim((string) $permission->name);
            }
        }

        return $permissions;
    }

    /**
     * Status relationship
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function status_enum(): BelongsTo
    {
        return $this->belongsTo(Enumerate::class, 'status')->where('type', 'user_status');
    }
}
