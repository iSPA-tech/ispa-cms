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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Multi-factor authentication challenge session for step-up verification.
 *
 * Tracks a user's authentication challenge lifecycle, including required
 * and completed MFA methods, email code verification, attempt counting,
 * and session context (IP, user agent, remember-me preference).
 *
 * ==== Properties - Fields
 * @property int         $id
 * @property string      $uuid                   Unique identifier for API exposure
 * @property int         $user_id                Foreign key to the challenged user
 * @property string      $status                 Challenge status (pending, completed, cancelled, expired, failed)
 * @property array|null  $required_methods       List of MFA methods required to complete
 * @property array|null  $completed_methods      List of MFA methods already completed
 * @property \Carbon\Carbon|null $expires_at            Challenge expiration timestamp
 * @property int|null    $attempt_count           Number of verification attempts made
 * @property string|null $email_code_hash         Hashed email verification code
 * @property \Carbon\Carbon|null $email_code_sent_at    When the email code was sent
 * @property \Carbon\Carbon|null $email_code_expires_at When the email code expires
 * @property string|null $ip_address             Client IP address at challenge time
 * @property string|null $user_agent             Client user agent string
 * @property bool        $remember               Whether to remember the device after completion
 * @property array|null  $context                Additional challenge context metadata
 * @property \Carbon\Carbon|null $completed_at   When the challenge was completed
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * ==== Relationships
 * @property-read \App\Models\User $user The user being challenged
 *
 * @mixin \Eloquent
 * @package Cms\Core\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AuthChallenge extends Model
{
    use HasFactory, HasUuid;

    protected $table = Tables::AUTH_CHALLENGES;

    protected $fillable = [
        'uuid',
        'user_id',
        'status',
        'required_methods',
        'completed_methods',
        'expires_at',
        'attempt_count',
        'email_code_hash',
        'email_code_sent_at',
        'email_code_expires_at',
        'ip_address',
        'user_agent',
        'remember',
        'context',
        'completed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'required_methods' => 'array',
        'completed_methods' => 'array',
        'expires_at' => 'datetime',
        'email_code_sent_at' => 'datetime',
        'email_code_expires_at' => 'datetime',
        'remember' => 'boolean',
        'context' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user being challenged for authentication.
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
