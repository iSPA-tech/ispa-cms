<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Closure;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Registry of "things waiting for me" providers shared by the dashboard strip and the my-tasks endpoint.
 */
class AttentionRegistry
{
    private const MAX_ITEMS = 10;

    /** @var array<string, array{ability: string|Closure, count: callable, items: ?callable, meta: array<string, mixed>}> */
    private array $providers = [];

    /** @var array<string, bool> */
    private array $logged = [];

    /**
     * Register (or replace) a provider.
     *
     * @param string $key Unique provider key.
     * @param string|Closure $ability Gate ability name, or a closure receiving the user and returning bool.
     * @param callable $count Receives the user, returns the pending count.
     * @param callable|null $items Receives the user, returns item rows.
     * @param array<string, mixed> $meta Optional route, query and tone.
     */
    public function register(string $key, string|Closure $ability, callable $count, ?callable $items = null, array $meta = []): void
    {
        $this->providers[$key] = ['ability' => $ability, 'count' => $count, 'items' => $items, 'meta' => $meta];
    }

    /**
     * Entries the user may see, with a positive count.
     *
     * @param Authenticatable $user Current user.
     * @param bool $withItems Include item rows (capped at 10).
     * @return array<int, array<string, mixed>>
     */
    public function forUser(Authenticatable $user, bool $withItems = false): array
    {
        $out = [];
        foreach ($this->providers as $key => $p) {
            try {
                $allowed = $p['ability'] instanceof Closure
                    ? (bool) ($p['ability'])($user)
                    : Gate::forUser($user)->allows($p['ability']);
                if (! $allowed) {
                    continue;
                }
                $count = (int) ($p['count'])($user);
                if ($count <= 0) {
                    continue;
                }
                $entry = ['key' => $key, 'count' => $count, 'route' => $p['meta']['route'] ?? null];
                foreach (['query', 'tone'] as $opt) {
                    if (isset($p['meta'][$opt])) {
                        $entry[$opt] = $p['meta'][$opt];
                    }
                }
                if ($withItems && $p['items'] !== null) {
                    $entry['items'] = array_slice(array_values((array) ($p['items'])($user)), 0, self::MAX_ITEMS);
                }
                $out[] = $entry;
            } catch (Throwable $e) {
                $this->logOnce($key, $e);
            }
        }

        return $out;
    }

    /**
     * Log a provider failure once per key.
     */
    private function logOnce(string $key, Throwable $e): void
    {
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        try {
            CMSLogger::warning(['message' => 'Attention provider failed', 'key' => $key, 'error' => $e->getMessage()]);
        } catch (Throwable) {
            // Logging must never break the response.
        }
    }
}
