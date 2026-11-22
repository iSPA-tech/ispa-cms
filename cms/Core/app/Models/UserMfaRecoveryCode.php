<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use App\Models\User;
use App\Constants\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One-time recovery code for MFA factor bypass.
 *
 * Provides emergency access when a user cannot use their primary MFA factor.
 * Each code is single-use, stored as a hash with a visible hint for
 * identification. Tracks whether and when the code was consumed.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property int         $factor_id    Foreign key to the associated MFA factor
 * @property int         $user_id      Foreign key to the owning user
 * @property string      $code_hash    Hashed recovery code value
 * @property string|null $code_hint    Partial code hint for user identification (e.g. "AB**-**XZ")
 * @property \Carbon\Carbon|null $used_at When the code was consumed
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read UserMfaFactor     $factor The MFA factor this recovery code belongs to
 * @property-read \App\Models\User  $user   The user who owns this recovery code
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UserMfaRecoveryCode extends Model
{
    use HasFactory;

    protected $table = Tables::USER_MFA_RECOVERY_CODES;

    protected $fillable = [
        'factor_id',
        'user_id',
        'code_hash',
        'code_hint',
        'used_at',
    ];

    protected $casts = [
        'factor_id' => 'integer',
        'user_id' => 'integer',
        'used_at' => 'datetime',
    ];

    /**
     * Get the MFA factor this recovery code belongs to.
     * @return BelongsTo
     */
    public function factor(): BelongsTo
    {
        return $this->belongsTo(UserMfaFactor::class, 'factor_id', 'id');
    }

    /**
     * Get the user who owns this recovery code.
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
