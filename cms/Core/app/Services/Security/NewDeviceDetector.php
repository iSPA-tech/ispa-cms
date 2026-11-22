<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use Cms\Core\Models\ApiAccessToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Decides whether a token about to be issued comes from a device or network the account has not used recently
 *. Only the coarse labels stored on tokens are compared; an account with no earlier token that carries a
 * device record is never "new" (its first sign-in sends nothing).
 *
 * @package Cms\Core\Services\Security
 */
class NewDeviceDetector
{
    /** Days of token history that count as "recent". */
    public const RECENT_DAYS = 90;

    /**
     * Whether the network label or the device label is absent from the account's recent tokens.
     *
     * Call before the new token is stored and before the same-device token is replaced.
     *
     * @param Model $user Token owner.
     * @param string|null $network Coarse network label of the new token (see ApiTokenIssuer::networkLabel()).
     * @param string|null $device Device label of the new token (see ApiTokenIssuer::deviceLabel()).
     * @return bool
     */
    public function isNewDevice(Model $user, ?string $network, ?string $device): bool
    {
        if ($network === null && $device === null) {
            return false;
        }

        $recent = ApiAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->where('created_at', '>=', Carbon::now()->subDays(self::RECENT_DAYS))
            ->where(static fn ($query) => $query->whereNotNull('ip_address')->orWhereNotNull('user_agent'));

        if (! (clone $recent)->exists()) {
            return false;
        }

        return ! $recent
            ->when($network === null, static fn ($q) => $q->whereNull('ip_address'), static fn ($q) => $q->where('ip_address', $network))
            ->when($device === null, static fn ($q) => $q->whereNull('user_agent'), static fn ($q) => $q->where('user_agent', $device))
            ->exists();
    }
}
