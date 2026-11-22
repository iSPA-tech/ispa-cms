<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Models\User;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Resources\ApiTokenResource;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenRevoker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API token list and revoke routes of the admin API.
 *
 * Any signed-in operator uses `mine`, `mineDestroy` and `revokeOthers` for the tokens of their own account; the
 * Webmaster-only routes (`admin.webmaster`) list everybody's tokens and revoke one token, all tokens of a user, of an
 * API or of the site. Every revoke route sits behind `security.stepup:revoke_tokens` (bulk ones consume the grant) and
 * always spares the token that carries the request: revoking it answers 422 and the session ends only through
 * sign-out. The bulk Webmaster routes also need the typed word {@see self::CONFIRM_WORD} in the body. A token that is
 * not the caller's own answers 404 on the `mine` routes, so another account's token is never revealed. Responses carry
 * {@see ApiTokenResource} rows and counts, never a token, a hash or a full network address; the revoker writes one
 * audit event per call with counts only.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 */
class SecurityTokenController extends AdminController
{
    /** Word the operator types to confirm a bulk Webmaster revoke. */
    public const CONFIRM_WORD = 'REVOKE';

    /** Most rows one page of the Webmaster listing returns. */
    private const MAX_PER_PAGE = 100;

    /**
     * @param TokenRevoker $revoker The one place that revokes tokens.
     */
    public function __construct(private readonly TokenRevoker $revoker)
    {
        parent::__construct();
    }

    /**
     * Live tokens of the caller's own account, any audience; the one in use is marked `current`.
     *
     * @param Request $request Signed-in request.
     * @return mixed Admin API response.
     */
    public function mine(Request $request): mixed
    {
        $tokens = $this->live($this->accountTokens()->where('tokenable_id', $request->user()->getKey()))
            ->orderByDesc('last_used_at')->orderByDesc('id')->limit(self::MAX_PER_PAGE)->get();

        return ires()->data(ApiTokenResource::collection($tokens)->resolve($request));
    }

    /**
     * Webmaster: live tokens of every account, with filters (user uuid, audience, last used before, never expiring, legacy).
     *
     * @param Request $request Query: user, audience, last_used_before, never_expiring, legacy, per_page, page.
     * @return mixed Admin API response with `items` and `meta`.
     */
    public function index(Request $request): mixed
    {
        $filters = $request->validate([
            'user' => ['sometimes', 'string', 'uuid'],
            'audience' => ['sometimes', 'string', 'in:' . implode(',', TokenAudience::userAudiences())],
            'last_used_before' => ['sometimes', 'date'],
            'never_expiring' => ['sometimes', 'boolean'],
            'legacy' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        $query = $this->live($this->accountTokens())->with('tokenable:id,uuid,name');
        if (isset($filters['user'])) {
            $query->whereIn('tokenable_id', User::query()->where('uuid', $filters['user'])->select('id'));
        }
        if (isset($filters['audience'])) {
            $query->where('audience', $filters['audience']);
        }
        if (isset($filters['last_used_before'])) {
            $before = Carbon::parse($filters['last_used_before']);
            $query->where(fn (Builder $q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $before));
        }
        if ($request->boolean('never_expiring')) {
            $query->whereNull('expires_at');
        }
        if ($request->boolean('legacy')) {
            $query->whereNull('audience');
        }

        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));

        return ires()->data([
            'items' => ApiTokenResource::collection($page->getCollection())->resolve($request),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * End one other token of the caller's own account.
     *
     * @param Request $request Signed-in request with a valid step-up grant.
     * @param string $uuid Token uuid.
     * @return mixed Admin API response.
     */
    public function mineDestroy(Request $request, string $uuid): mixed
    {
        $token = $this->accountTokens()->where('tokenable_id', $request->user()->getKey())->where('uuid', $uuid)->first();

        return $this->revokeOne($request, $token);
    }

    /**
     * End every other token of the caller's own account (consumes the grant).
     *
     * @param Request $request Signed-in request with a valid step-up grant.
     * @return mixed Admin API response with the count.
     */
    public function revokeOthers(Request $request): mixed
    {
        $revoked = $this->revoker->revokeForUser($request->user(), $this->current($request));

        return ires()->data(['revoked' => $revoked, 'current_kept' => true]);
    }

    /**
     * Webmaster: end one token of any account other than the one in use.
     *
     * @param Request $request Signed-in request with a valid step-up grant.
     * @param string $uuid Token uuid.
     * @return mixed Admin API response.
     */
    public function destroy(Request $request, string $uuid): mixed
    {
        return $this->revokeOne($request, $this->accountTokens()->where('uuid', $uuid)->first());
    }

    /**
     * Webmaster: end every token of one account (the Webmaster's own current token is spared); needs the typed word.
     *
     * @param Request $request Body: user (uuid), confirm.
     * @return mixed Admin API response with the count.
     */
    public function revokeUser(Request $request): mixed
    {
        $data = $request->validate(['user' => ['required', 'string', 'uuid']]);
        if (! $this->typedWord($request)) {
            return $this->needsWord();
        }
        $user = User::query()->where('uuid', $data['user'])->first();
        if ($user === null) {
            return $this->notFound();
        }

        return ires()->data(['revoked' => $this->revoker->revokeForUser($user, $this->current($request)), 'current_kept' => true]);
    }

    /**
     * Webmaster: end every token of one API family (audience); needs the typed word.
     *
     * @param Request $request Body: audience, confirm.
     * @return mixed Admin API response with the count.
     */
    public function revokeAudience(Request $request): mixed
    {
        $data = $request->validate(['audience' => ['required', 'string', 'in:' . implode(',', TokenAudience::userAudiences())]]);
        if (! $this->typedWord($request)) {
            return $this->needsWord();
        }

        return ires()->data(['revoked' => $this->revoker->revokeForAudience($data['audience'], $this->current($request)), 'current_kept' => true]);
    }

    /**
     * Webmaster: end every token of the site, legacy ones included; needs the typed word.
     *
     * @param Request $request Body: confirm.
     * @return mixed Admin API response with the count.
     */
    public function revokeAll(Request $request): mixed
    {
        if (! $this->typedWord($request)) {
            return $this->needsWord();
        }

        return ires()->data(['revoked' => $this->revoker->revokeAll($this->current($request)), 'current_kept' => true]);
    }

    /**
     * Revoke one resolved token, refusing the token in use and a missing token.
     *
     * @param Request $request Signed-in request.
     * @param ApiAccessToken|null $token Token found for the uuid, or null.
     * @return mixed Admin API response.
     */
    private function revokeOne(Request $request, ?ApiAccessToken $token): mixed
    {
        if ($token === null) {
            return $this->notFound();
        }
        $current = $this->current($request);
        if ($current !== null && (int) $current->getKey() === (int) $token->getKey()) {
            return ires()->error()->code(422)->errorCode('CURRENT_TOKEN_NOT_REVOCABLE')
                ->message('This is the session you are using. Sign out to end it.');
        }

        return ires()->data(['revoked' => $this->revoker->revokeToken($token, $current)]);
    }

    /**
     * The token that carries the request, or null for a transient (browser-session) token.
     *
     * @param Request $request Signed-in request.
     * @return PersonalAccessToken|null
     */
    private function current(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /**
     * Whether the body carries the typed confirmation word.
     *
     * @param Request $request Request.
     * @return bool
     */
    private function typedWord(Request $request): bool
    {
        return hash_equals(self::CONFIRM_WORD, (string) $request->input('confirm', ''));
    }

    /**
     * 422 asking for the typed word.
     *
     * @return mixed
     */
    private function needsWord(): mixed
    {
        return ires()->error()->code(422)->errorCode('CONFIRMATION_WORD_REQUIRED')
            ->message('Type the confirmation word to continue.');
    }

    /**
     * 404 that does not say whether the token exists for someone else.
     *
     * @return mixed
     */
    private function notFound(): mixed
    {
        return ires()->error()->code(404)->errorCode('TOKEN_NOT_FOUND')->message('Not found.');
    }

    /**
     * Tokens owned by accounts (Game player tokens are never listed or revoked here).
     *
     * @return Builder<ApiAccessToken>
     */
    private function accountTokens(): Builder
    {
        return ApiAccessToken::query()->where('tokenable_type', (new User())->getMorphClass());
    }

    /**
     * Keep tokens that have not expired.
     *
     * @param Builder<ApiAccessToken> $query Token query.
     * @return Builder<ApiAccessToken>
     */
    private function live(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
