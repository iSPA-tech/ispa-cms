<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Listeners;

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Services\Security\TokenRevoker;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * Ends API tokens when an account event makes the old sessions unsafe.
 *
 * Hooked once on the model events, so every controller, service, console command and queue job that saves an account
 * is covered: the status leaving active (locked, deactivated), the type becoming Webmaster or Admin, the account being
 * soft or force deleted (the Account privacy deletion included), and a second factor being switched on. Password
 * change and reset are handled where the password is written (Account services), because only there the token that
 * carries the request is known.
 *
 * Every trigger goes through `TokenRevoker`, so each writes exactly one `security.tokens.revoked` audit event with
 * counts only. Role or permission changes are not a trigger here (no code authorises from the issue-time snapshot
 * except the `AgenticAuthorization` fallback, see the task result).
 *
 * @package Cms\Core\Listeners
 */
final class RevokeTokensOnAccountChange
{
    /**
     * @param TokenRevoker $revoker The one place that revokes API tokens.
     */
    public function __construct(private readonly TokenRevoker $revoker)
    {
    }

    /**
     * Handle `eloquent.updated: App\Models\User`.
     *
     * @param User $user Account that was just saved.
     * @return void
     */
    public function userUpdated(User $user): void
    {
        if ($user->wasChanged('status') && ! $this->isActive($user->getAttribute('status'))) {
            $this->revoker->revokeForUser($user, null);

            return;
        }

        if ($user->wasChanged('type') && $this->isStaffType($user->getAttribute('type')) && ! $this->isStaffType($user->getOriginal('type'))) {
            // Every token ends, legacy ones included: a freshly promoted account must sign in again as staff.
            $this->revoker->revokeForUser($user, null);
        }
    }

    /**
     * Handle `eloquent.deleted: App\Models\User` (fires for soft and force deletes).
     *
     * @param User $user Account that was just deleted.
     * @return void
     */
    public function userDeleted(User $user): void
    {
        $this->revoker->revokeForUser($user, null);
    }

    /**
     * Handle `eloquent.saved: Cms\Core\Models\UserMfaFactor`: a factor that has just been enabled.
     *
     * @param UserMfaFactor $factor Factor that was just saved.
     * @return void
     */
    public function factorSaved(UserMfaFactor $factor): void
    {
        if (! $factor->wasChanged('enabled_at') || $factor->getAttribute('enabled_at') === null) {
            return;
        }

        $user = User::withTrashed()->find($factor->getAttribute('user_id'));
        if (! $user instanceof User) {
            return;
        }

        $this->revoker->revokeForUser($user, $this->requestToken($user));
    }

    /**
     * The token that carries the current request when the request belongs to the same account, so the person who
     * switched the factor on keeps the session they are in.
     *
     * @param User $account Account whose tokens end.
     * @return HasAbilities|null
     */
    private function requestToken(User $account): ?HasAbilities
    {
        $requestUser = request()->user();
        if (! $requestUser instanceof User || (int) $requestUser->getKey() !== (int) $account->getKey()) {
            return null;
        }

        $token = $requestUser->currentAccessToken();

        return $token instanceof HasAbilities ? $token : null;
    }

    /**
     * Whether a stored status means the account may sign in.
     *
     * @param mixed $status Stored status value.
     * @return bool
     */
    private function isActive(mixed $status): bool
    {
        return in_array((string) $status, [UserStatusEnum::USER_ACTIVE->value, 'active'], true);
    }

    /**
     * Whether a stored type is Webmaster or Admin.
     *
     * @param mixed $type Stored type value.
     * @return bool
     */
    private function isStaffType(mixed $type): bool
    {
        return $type !== null && in_array((int) $type, [UserType::WEBMASTER, UserType::ADMIN], true);
    }
}
