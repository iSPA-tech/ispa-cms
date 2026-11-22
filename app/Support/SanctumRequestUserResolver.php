<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Finds the user behind a request that may carry a Sanctum bearer token, for middleware and controllers that read the
 * token themselves (the admin gate, the Game middleware, a module's report endpoint).
 *
 * a bearer token is refused in every mode when it is past its `expires_at` or older than
 * `sanctum.expiration` minutes, the same rule Sanctum's guard applies; before, this resolver accepted it. A token whose
 * owner no longer exists (soft deleted) resolves to nobody, as before. With `$audiences`, the shared {@see TokenGate}
 * also decides on audience, owner status and idle time in the site's mode, and a refused token resolves to nobody.
 */
class SanctumRequestUserResolver
{
    /**
     * The user of the request: the bearer token's owner when a bearer token is sent, otherwise the session user.
     *
     * @param Request $request The incoming request.
     * @param list<string>|null $audiences Audiences the caller accepts (and {@see TokenGate::SESSION} for a browser
     *                                     session); null skips the audience, status and idle checks.
     * @return mixed The owner, or null when no usable credential is present.
     */
    public static function resolve(Request $request, ?array $audiences = null): mixed
    {
        if ($audiences !== null) {
            $decision = app(TokenGate::class)->forRequest($request, $audiences, 'resolver');

            return $decision['outcome'] === TokenGate::DENY ? null : $decision['owner'];
        }

        if ($request->bearerToken()) {
            return self::bearerToken($request)?->tokenable;
        }

        return self::sessionUser($request);
    }

    /**
     * The bearer token of the request when it exists, is not expired and has an owner; the owner carries it as its current
     * access token. Reuses the token Sanctum's guard already loaded for the same bearer, so an `auth:sanctum` route followed
     * by the admin gate looks the token and its owner up once.
     */
    public static function bearerToken(Request $request): ?PersonalAccessToken
    {
        $plain = $request->bearerToken();
        if (! $plain) {
            return null;
        }

        $token = self::guardToken($plain);
        if ($token === null) {
            $model = Sanctum::$personalAccessTokenModel;
            $token = $model::findToken($plain);
        }

        if (! $token instanceof PersonalAccessToken || self::isExpired($token)) {
            return null;
        }

        $owner = $token->tokenable;
        if (! $owner) {
            return null;
        }

        if (method_exists($owner, 'withAccessToken')) {
            $owner->withAccessToken($token);
        }

        return $token;
    }

    /**
     * The user of a request without a bearer token (browser session through Sanctum's stateful guard or the web guard).
     */
    public static function sessionUser(Request $request): mixed
    {
        return $request->user('sanctum')
            ?? auth('sanctum')->user()
            ?? $request->user();
    }

    /**
     * Whether the token is past its own expiry or older than the global Sanctum expiration.
     */
    public static function isExpired(PersonalAccessToken $token): bool
    {
        $minutes = config('sanctum.expiration');
        if ($minutes && $token->created_at !== null && $token->created_at->lte(now()->subMinutes((int) $minutes))) {
            return true;
        }

        return $token->expires_at !== null && $token->expires_at->isPast();
    }

    /**
     * The token Sanctum's guard authenticated for this request, when it is the row the bearer names (`<id>|<secret>`).
     * The guard has verified the secret of that row against the same bearer, so the row can be reused as is.
     */
    private static function guardToken(string $plain): ?PersonalAccessToken
    {
        if (! str_contains($plain, '|')) {
            return null;
        }

        $guard = auth()->guard('sanctum');
        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($token instanceof PersonalAccessToken && $token->exists && (string) $token->getKey() === explode('|', $plain, 2)[0]) {
            return $token;
        }

        return null;
    }
}
