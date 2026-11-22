<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Holds the entitlement states the platform hub reported for this site's private and paid modules and shows
 * Webmasters a task while one is in grace, expired or revoked.
 *
 * The daily check (`cms:module-revocation-check`) stores the hub's optional `entitlements` list beside the revocations.
 * A state only drives what the site shows and offers: an expired or revoked module keeps running and is never
 * disabled, it just gets no update offer. A missing or failed hub answer leaves the stored result untouched.
 */
final class EntitlementAttentionProvider
{
    public const KEY = 'core_module_entitlement';

    /** Hub states a site accepts and stores. */
    public const STATES = ['entitled', 'grace', 'expired', 'revoked'];

    /** States that need the Webmaster's attention. */
    public const ATTENTION_STATES = ['grace', 'expired', 'revoked'];

    /** States for which the hub refuses updates, so the site stops offering them. */
    public const BLOCKING_STATES = ['expired', 'revoked'];

    /**
     * Register the provider with the shared attention registry.
     */
    public static function register(AttentionRegistry $registry): void
    {
        $registry->register(
            self::KEY,
            static fn (Authenticatable $user): bool => $user instanceof User && $user->isWebmaster(),
            static fn (Authenticatable $user): int => self::count(),
            null,
            ['route' => 'setting-modules']
        );
    }

    /**
     * Site-unique cache key of the stored result.
     */
    public static function cacheKey(): string
    {
        return 'platform:module-entitlements:v1:' . sha1(
            (string) config('ispa-cms.cms_domain', '') . '|' . (string) config('ispa-cms.platform_hub_url', '')
        );
    }

    /**
     * Store a check result (no expiry; the next accepted hub answer replaces it).
     *
     * @param array<string, array{state: string, expires_at: ?string, grace_until: ?string}> $states keyed by module name
     */
    public static function store(array $states): void
    {
        Cache::forever(self::cacheKey(), ['checked_at' => now()->toIso8601String(), 'states' => $states]);
    }

    /**
     * Stored states keyed by module name; empty when nothing was stored yet.
     *
     * @return array<string, array{state: string, expires_at: ?string, grace_until: ?string}>
     */
    public static function states(): array
    {
        $stored = Cache::get(self::cacheKey());

        return is_array($stored) && is_array($stored['states'] ?? null) ? $stored['states'] : [];
    }

    /**
     * Stored state of one module, or null when the hub reported none.
     *
     * @return array{state: string, expires_at: ?string, grace_until: ?string}|null
     */
    public static function stateFor(string $name): ?array
    {
        $entry = self::states()[$name] ?? null;

        return is_array($entry) && in_array($entry['state'] ?? null, self::STATES, true) ? $entry : null;
    }

    /**
     * Whether the hub would refuse updates of this module (state expired or revoked).
     */
    public static function blocksUpdates(string $name): bool
    {
        return in_array(self::stateFor($name)['state'] ?? null, self::BLOCKING_STATES, true);
    }

    /**
     * Number of installed modules whose state is grace, expired or revoked.
     */
    public static function count(): int
    {
        $states = self::states();
        if ($states === []) {
            return 0;
        }

        $catalog = app(ModuleCatalog::class);
        $count = 0;
        try {
            foreach (ModuleFacade::all() as $module) {
                $name = $module->getName();
                $state = $states[$name]['state'] ?? null;
                if (in_array($state, self::ATTENTION_STATES, true) && $catalog->installedState($name)['installed'] !== 'none') {
                    $count++;
                }
            }
        } catch (Throwable) {
            return $count;
        }

        return $count;
    }
}
