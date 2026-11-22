<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\ApiAccessToken;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The one place that revokes API tokens.
 *
 * Every method takes the token to spare as a required argument: an API path passes the token that carries the request
 * (`$request->user->currentAccessToken`), which is never deleted, also when it matches the filter (decision
 * 2026-11-11: the current session ends only through sign-out). A browser-session caller holds a transient token, which
 * spares nothing. Only tokens owned by `App\Models\User` accounts are touched: Game player tokens keep their own
 * sessions and are revoked by the Game modules (spec section 6.4).
 *
 * Each call writes exactly one audit event (`security.tokens.revoked`) with the scope, the audience filter and counts;
 * never a token, a hash or a uuid.
 *
 * @package Cms\Core\Services\Security
 */
class TokenRevoker
{
    /** Audit action written by every call. */
    public const AUDIT_ACTION = 'security.tokens.revoked';

    /**
     * Revoke one token unless it is the one to spare.
     *
     * @param PersonalAccessToken $token Token to revoke; must belong to an account.
     * @param HasAbilities|null $spare Token that carries the request (never revoked), or null.
     * @return int Number of tokens revoked (0 or 1).
     * @throws InvalidArgumentException When the token is not owned by an account (for example a Game player token).
     */
    public function revokeToken(PersonalAccessToken $token, ?HasAbilities $spare): int
    {
        if ($token->getAttribute('tokenable_type') !== $this->userMorphClass()) {
            throw new InvalidArgumentException('TOKEN_NOT_REVOCABLE_HERE');
        }

        $spareId = $this->spareId($spare);
        $spared = $spareId !== null && (int) $token->getKey() === $spareId;
        $revoked = $spared ? 0 : ApiAccessToken::query()->whereKey($token->getKey())->delete();

        $this->audit('token', $token->getAttribute('audience'), $revoked, $spared, ApiAccessToken::class, (int) $token->getKey());

        return $revoked;
    }

    /**
     * Revoke every token of one account, optionally of one audience only, but the one to spare.
     *
     * @param User $user Account whose tokens end.
     * @param HasAbilities|null $spare Token that carries the request (never revoked), or null.
     * @param string|null $audience Limit to one audience; null for every token of the account, legacy ones included.
     * @return int Number of tokens revoked.
     * @throws InvalidArgumentException When the audience is unknown.
     */
    public function revokeForUser(User $user, ?HasAbilities $spare, ?string $audience = null): int
    {
        $query = $this->accountTokens()->where('tokenable_id', $user->getKey());
        if ($audience !== null) {
            $query->where('audience', TokenAudience::assertValid($audience));
        }

        return $this->revokeQuery($query, $spare, 'user', $audience, User::class, (int) $user->getKey());
    }

    /**
     * Revoke every account token of one audience (one API family) but the one to spare.
     *
     * @param string $audience One of TokenAudience::userAudiences(); `game` is Game-owned and refused.
     * @param HasAbilities|null $spare Token that carries the request (never revoked), or null.
     * @return int Number of tokens revoked.
     * @throws InvalidArgumentException When the audience is unknown or `game`.
     */
    public function revokeForAudience(string $audience, ?HasAbilities $spare): int
    {
        if (! in_array($audience, TokenAudience::userAudiences(), true)) {
            throw new InvalidArgumentException('TOKEN_AUDIENCE_NOT_REVOCABLE_HERE');
        }

        return $this->revokeQuery($this->accountTokens()->where('audience', $audience), $spare, 'audience', $audience);
    }

    /**
     * Revoke every account token of the site, legacy ones included, but the one to spare.
     *
     * @param HasAbilities|null $spare Token that carries the request (never revoked), or null.
     * @return int Number of tokens revoked.
     */
    public function revokeAll(?HasAbilities $spare): int
    {
        return $this->revokeQuery($this->accountTokens(), $spare, 'all', null);
    }

    /**
     * Delete the rows of a query except the spared token and write the audit event.
     *
     * @param Builder<ApiAccessToken> $query Rows to revoke.
     * @param HasAbilities|null $spare Token never revoked.
     * @param string $scope Audit scope: user, audience or all.
     * @param string|null $audience Audience filter, for the audit event.
     * @param string $targetType Audit target type.
     * @param int $targetId Audit target id (0 for site-wide scopes).
     * @return int Number of tokens revoked.
     */
    private function revokeQuery(
        Builder $query,
        ?HasAbilities $spare,
        string $scope,
        ?string $audience,
        string $targetType = ApiAccessToken::class,
        int $targetId = 0,
    ): int {
        $spareId = $this->spareId($spare);
        $spared = $spareId !== null && (clone $query)->whereKey($spareId)->exists();
        if ($spareId !== null) {
            $query->whereKeyNot($spareId);
        }

        $revoked = $query->delete();
        $this->audit($scope, $audience, $revoked, $spared, $targetType, $targetId);

        return $revoked;
    }

    /**
     * Query of every token owned by an `App\Models\User` account.
     *
     * @return Builder<ApiAccessToken>
     */
    private function accountTokens(): Builder
    {
        return ApiAccessToken::query()->where('tokenable_type', $this->userMorphClass());
    }

    /**
     * Morph class stored in `tokenable_type` for accounts.
     *
     * @return string
     */
    private function userMorphClass(): string
    {
        return (new User())->getMorphClass();
    }

    /**
     * Id of the token to spare; a transient (browser-session) token spares nothing.
     *
     * @param HasAbilities|null $spare Token that carries the request.
     * @return int|null
     */
    private function spareId(?HasAbilities $spare): ?int
    {
        return $spare instanceof PersonalAccessToken && $spare->getKey() !== null ? (int) $spare->getKey() : null;
    }

    /**
     * Write the one audit event of a call: scope, audience filter and counts only.
     *
     * @param string $scope token, user, audience or all.
     * @param string|null $audience Audience filter or the audience of the single token.
     * @param int $revoked Tokens deleted.
     * @param bool $spared Whether the spared token matched the filter and was kept.
     * @param string $targetType Audit target type.
     * @param int $targetId Audit target id.
     * @return void
     */
    private function audit(string $scope, ?string $audience, int $revoked, bool $spared, string $targetType, int $targetId): void
    {
        event(new AuditLogEvent(
            self::AUDIT_ACTION,
            ['scope' => $scope, 'audience' => $audience, 'revoked' => $revoked, 'spared' => $spared ? 1 : 0],
            $targetType,
            $targetId,
            'fa fa-key',
            'warning',
        ));
    }
}
