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
 * Shows Webmasters a "Module version revoked" task and holds the stored result of the daily revocation check.
 *
 * The check command (`cms:module-revocation-check`) stores the hub answer under a site-unique cache key. A module counts
 * only while it is enabled and its installed version equals the revoked version. Nothing is ever disabled automatically.
 */
final class RevocationAttentionProvider
{
    public const KEY = 'core_module_revoked';

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
     * Site-unique cache key of the stored check result.
     */
    public static function cacheKey(): string
    {
        return 'platform:module-revocations:v1:' . sha1(
            (string) config('ispa-cms.cms_domain', '') . '|' . (string) config('ispa-cms.platform_hub_url', '')
        );
    }

    /**
     * Store a check result (no expiry; the next successful run replaces it).
     *
     * @param array<string, array{version: string, reason: ?string, revoked_at: ?string}> $revoked
     */
    public static function store(array $revoked): void
    {
        Cache::forever(self::cacheKey(), ['checked_at' => now()->toIso8601String(), 'revoked' => $revoked]);
    }

    /**
     * Stored revoked entries keyed by module name; empty when nothing was stored yet.
     *
     * @return array<string, array{version: string, reason: ?string, revoked_at: ?string}>
     */
    public static function revoked(): array
    {
        $stored = Cache::get(self::cacheKey());

        return is_array($stored) && is_array($stored['revoked'] ?? null) ? $stored['revoked'] : [];
    }

    /**
     * The stored entry for a module when it matches the installed version, else null.
     *
     * @param array<string, array{version: string, reason: ?string, revoked_at: ?string}> $revoked
     * @return array{version: string, reason: ?string, revoked_at: ?string}|null
     */
    public static function matches(array $revoked, string $name, ?string $installedVersion): ?array
    {
        $entry = $revoked[$name] ?? null;

        return is_array($entry) && $installedVersion !== null && ($entry['version'] ?? null) === $installedVersion ? $entry : null;
    }

    /**
     * Number of enabled modules whose installed version is the revoked one.
     */
    public static function count(): int
    {
        $revoked = self::revoked();
        if ($revoked === []) {
            return 0;
        }

        $catalog = app(ModuleCatalog::class);
        $count = 0;
        try {
            foreach (ModuleFacade::allEnabled() as $module) {
                $name = $module->getName();
                if (isset($revoked[$name]) && self::matches($revoked, $name, $catalog->installedState($name)['installed_version']) !== null) {
                    $count++;
                }
            }
        } catch (Throwable) {
            return $count;
        }

        return $count;
    }
}
