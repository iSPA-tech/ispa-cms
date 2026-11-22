<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Models;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum token model of the application (registered in CoreServiceProvider).
 *
 * Adds the audience and device columns: `uuid` (public identifier of a token in the token pages), `audience` (the API family the
 * token was issued for, see TokenAudience; null = legacy token), `ip_address` (coarse network label: IPv4 /24 or
 * IPv6 /64), `user_agent` (short browser or device label, never the raw header) and `issued_via` (the issuing flow).
 *
 * Every column is nullable and nothing is backfilled: tokens issued before tokens carried an audience keep working with them null. The
 * uuid is assigned on create only when the column exists, so the model also works on a database whose migration has
 * not run yet and on test tables built by hand.
 *
 * @property string|null $uuid
 * @property string|null $audience
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $issued_via
 *
 * @package Cms\Core\Models
 */
class ApiAccessToken extends PersonalAccessToken
{
    /**
     * The table is the Sanctum table; set explicitly because the class name differs from it.
     *
     * @var string
     */
    protected $table = 'personal_access_tokens';

    /**
     * Mass-assignable attributes: the Sanctum ones plus the audience and device columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'uuid',
        'audience',
        'ip_address',
        'user_agent',
        'issued_via',
    ];

    /**
     * Assign a uuid to every new token when the column exists. Retrieval never writes (no backfill): the Sanctum guard
     * loads a token on every authenticated request.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(static function (self $token): void {
            if (! empty($token->getAttributes()['uuid'] ?? null)) {
                return;
            }
            if (static::hasAudienceColumns($token)) {
                $token->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    /**
     * Whether the table already has the audience and device columns (the migration adds them together, `uuid` stands for all).
     *
     * One schema lookup per call; it runs only when a token is created (sign-in), never per authenticated request.
     *
     * @param self|null $token Instance whose connection to inspect; a new instance when null.
     * @return bool
     */
    public static function hasAudienceColumns(?self $token = null): bool
    {
        $token ??= new static();

        return Schema::connection($token->getConnectionName())->hasColumn($token->getTable(), 'uuid');
    }
}
