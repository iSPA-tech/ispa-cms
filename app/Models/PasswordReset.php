<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Models;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Password Reset
 * About the Password Reset Entity
 *
 * @author malayvuong
 *
 * @since 1.0.0
 *
 * ==== Properties - Fields
 *
 * @property int id
 * @property int user_id
 * @property string code
 * @property string token
 * @property string valid_to
 * @property string created_at
 *
 * === Relationships
 */
class PasswordReset extends Model
{
    use HasFactory;

    /** @var string */
    public $table = Tables::PASSWORD_RESETS;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'code', 'token', 'valid_to',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
        'valid_to' => 'datetime',
    ];

    /**
     * User relationship
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-14, 14:41 ICT
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
