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
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Multi-factor authentication factor registered by a user.
 *
 * Represents a single MFA method (e.g. TOTP authenticator app) registered
 * to a user account. Tracks the encrypted secret, verification status,
 * enable/disable lifecycle, and usage history. Each user can have
 * multiple factors with one designated as primary.
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property string      $uuid                  Unique identifier for API exposure
 * @property int         $user_id               Foreign key to the owning user
 * @property string      $provider              MFA provider type (e.g. totp)
 * @property string|null $label                 User-assigned label for this factor
 * @property string|null $issuer                Issuer name shown in authenticator apps
 * @property string      $secret                Encrypted TOTP secret key
 * @property \Carbon\Carbon|null $verified_at        When the factor was first verified
 * @property \Carbon\Carbon|null $enabled_at         When the factor was enabled
 * @property \Carbon\Carbon|null $disabled_at        When the factor was disabled
 * @property \Carbon\Carbon|null $last_used_at       Last successful verification
 * @property \Carbon\Carbon|null $last_challenged_at Last time a challenge was issued
 * @property bool        $is_primary            Whether this is the user's primary factor
 * @property array|null  $metadata              Additional factor-specific metadata
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read \App\Models\User $user           The user who owns this factor
 * @property-read \Illuminate\Database\Eloquent\Collection|UserMfaRecoveryCode[] $recoveryCodes Recovery codes for this factor
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UserMfaFactor extends Model
{
    use HasFactory, HasUuid;

    public const PROVIDER_TOTP = 'totp';

    protected $table = Tables::USER_MFA_FACTORS;

    protected $fillable = [
        'uuid',
        'user_id',
        'provider',
        'label',
        'issuer',
        'secret',
        'verified_at',
        'enabled_at',
        'disabled_at',
        'last_used_at',
        'last_challenged_at',
        'is_primary',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'secret' => 'encrypted',
        'verified_at' => 'datetime',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_challenged_at' => 'datetime',
        'is_primary' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the user who owns this MFA factor.
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the recovery codes generated for this factor.
     * @return HasMany
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserMfaRecoveryCode::class, 'factor_id', 'id');
    }
}
