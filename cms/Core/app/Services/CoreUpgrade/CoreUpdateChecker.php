<?php

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Platform\PlatformHubClient;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Newest core version offered by the hub (the `core` entry of the signed catalog). Never throws: an unconnected
 * hub, an old hub without a core entry, or a bad answer all read as "no information" (null, or the value cached
 * before when there is one).
 */
final class CoreUpdateChecker
{
    public const CACHE_KEY = 'core-upgrade:latest:v1';

    public const TTL_SECONDS = 3600;

    /** @var (Closure(): PlatformHubClient)|null */
    private ?Closure $clientFactory;

    /**
     * @param  (callable(): PlatformHubClient)|null  $clientFactory  Builds the hub client lazily (default: from config).
     */
    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory !== null ? Closure::fromCallable($clientFactory) : null;
    }

    /**
     * A normal call answers from the cache for an hour. With `$refresh` the cache is skipped: the hub is asked and a
     * usable answer replaces the cached one; when the hub does not answer (or has no usable core entry) the value
     * cached before is kept and returned, never wiped.
     *
     * @return array{version: string, notes_url: ?string}|null
     */
    public function latest(bool $refresh = false): ?array
    {
        $cached = null;
        try {
            $stored = Cache::get(self::CACHE_KEY);
            $cached = is_array($stored) && isset($stored['version']) ? $stored : null;
            if ($cached !== null && ! $refresh) {
                return $cached;
            }

            $client = $this->clientFactory !== null ? ($this->clientFactory)() : new PlatformHubClient();
            $core = $client->get(ModuleCatalog::HUB_PATH)['core'] ?? null;
            $latest = $this->validated($core);
            if ($latest !== null) {
                Cache::put(self::CACHE_KEY, $latest, self::TTL_SECONDS);
            }

            return $latest ?? $cached;
        } catch (Throwable) {
            return $cached;
        }
    }

    /**
     * @return array{version: string, notes_url: ?string}|null
     */
    private function validated(mixed $core): ?array
    {
        if (! is_array($core) || ($core['kind'] ?? null) !== 'core') {
            return null;
        }
        $version = $core['version'] ?? null;
        if (! is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/', $version) !== 1) {
            return null;
        }
        $notes = $core['notes_url'] ?? null;
        $notes = is_string($notes) && str_starts_with($notes, 'https://') ? $notes : null;

        return ['version' => $version, 'notes_url' => $notes];
    }
}
