<?php

declare(strict_types=1);

namespace Cms\Core\Services\Dashboard;

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dashboard warnings for the Webmaster about API tokens: tokens that never expire (only while an audience is set to
 * "never"), legacy tokens without an audience, and the audience check being off. Counts only.
 */
final class TokenAttentionProvider
{
    public const KEY_NEVER = 'tokens_never_expire';
    public const KEY_LEGACY = 'tokens_legacy';
    public const KEY_ENFORCEMENT = 'tokens_enforcement_off';

    /** Admin route of the "Sessions and tokens" page. */
    public const ROUTE = 'setting-security-tokens';

    /**
     * Register the three entries with the shared attention registry.
     *
     * @param AttentionRegistry $registry Shared registry.
     * @return void
     */
    public static function register(AttentionRegistry $registry): void
    {
        $webmaster = static fn (Authenticatable $user): bool => $user instanceof User && $user->isWebmaster();
        // Each warning opens the "Sessions and tokens" page on the tab (and filter) that shows what it counts.
        $meta = static fn (array $query): array => ['tone' => 'warning', 'route' => self::ROUTE, 'query' => $query];
        $registry->register(self::KEY_NEVER, $webmaster, static fn (): int => self::neverCount(), null, $meta(['tab' => 'all', 'never' => '1']));
        $registry->register(self::KEY_LEGACY, $webmaster, static fn (): int => self::accountTokens()->whereNull('audience')->count(), null, $meta(['tab' => 'all', 'legacy' => '1']));
        $registry->register(self::KEY_ENFORCEMENT, $webmaster, static fn (): int => (new TokenSettings())->mode() === 'off' ? 1 : 0, null, $meta(['tab' => 'lifetime']));
    }

    /**
     * Account tokens without an expiry, counted only while at least one audience is on "never".
     *
     * @return int
     */
    public static function neverCount(): int
    {
        if ((new TokenSettings())->neverAudiences() === []) {
            return 0;
        }

        return self::accountTokens()->whereNull('expires_at')->count();
    }

    /**
     * Tokens owned by accounts.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ApiAccessToken>
     */
    private static function accountTokens(): \Illuminate\Database\Eloquent\Builder
    {
        return ApiAccessToken::query()->where('tokenable_type', (new User())->getMorphClass());
    }
}
