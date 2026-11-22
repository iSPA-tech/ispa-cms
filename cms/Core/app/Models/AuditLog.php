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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Class AuditLog
 * About the AuditLog Entity
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 1.0.0
 *
 * @property int $id
 * @property string $action
 * @property array $changed
 * @property string $target_type
 * @property int $target_id
 * @property string $ip_address
 * @property string $agent
 * @property string $url
 * @property array $options
 * @property int $user_id
 * @property string $created_at
 * @property string $updated_at
 * @property string $deleted_at
 *
 * ==== Relationships
 * @property-read MorphTo $target Target model (morph to)
 * @property-read User $creator User who performed the action
 *
 * @mixin \Eloquent
 */
class AuditLog extends Model
{
    use HasFactory, SoftDeletes;

    /** @var string */
    public $table = CoreTableConstant::AUDIT_LOGS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'action', 'changed', 'target_type', 'target_id', 'ip_address', 'agent', 'url', 'options', 'user_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'changed' => 'json',
        'options' => 'json',
    ];

    /**
     * Morph to target
     *
     * @return MorphTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /**
     * User Relationships
     *
     * @return BelongsTo
     * @author malayvuong
     * @since 1.0.0
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
