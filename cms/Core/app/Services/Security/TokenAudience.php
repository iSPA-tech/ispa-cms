<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use InvalidArgumentException;

/**
 * The closed list of API token audiences .
 *
 * A token carries exactly one audience in the `audience` column of `personal_access_tokens`. A null column means a
 * legacy token issued before tokens carried an audience; "legacy" is a state, not an audience. A new audience is added here only when a
 * new issuer exists.
 *
 * @package Cms\Core\Services\Security
 */
final class TokenAudience
{
    /** Staff (Webmaster or Admin) using the admin UI or the admin API. */
    public const ADMIN = 'admin';

    /** A person signed in through the Account API (portal, mobile apps, instructors, learners, SocialAuth). */
    public const ACCOUNT = 'account';

    /** The legacy v2 client API and the project public/mobile APIs signed in with the shared client key. */
    public const CLIENT = 'client';

    /** The back-end service integration signed in with `POST api/v3/auth/sign-in`. */
    public const SERVICE = 'service';

    /** Player tokens of cms/GameAuth; Game-owned, never revoked from the Core token pages. */
    public const GAME = 'game';

    /**
     * Every audience, in display order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ADMIN, self::ACCOUNT, self::CLIENT, self::SERVICE, self::GAME];
    }

    /**
     * The audiences whose tokens belong to `App\Models\User` accounts and may be revoked by Core.
     *
     * @return list<string>
     */
    public static function userAudiences(): array
    {
        return [self::ADMIN, self::ACCOUNT, self::CLIENT, self::SERVICE];
    }

    /**
     * Whether the value is one of the audiences of the closed list.
     *
     * @param string|null $audience Value to check.
     * @return bool
     */
    public static function isValid(?string $audience): bool
    {
        return $audience !== null && in_array($audience, self::all(), true);
    }

    /**
     * Return the audience unchanged, or fail when it is not on the closed list.
     *
     * @param string $audience Value to check.
     * @return string The same audience.
     * @throws InvalidArgumentException When the audience is unknown.
     */
    public static function assertValid(string $audience): string
    {
        if (! self::isValid($audience)) {
            throw new InvalidArgumentException('UNKNOWN_TOKEN_AUDIENCE');
        }

        return $audience;
    }
}
