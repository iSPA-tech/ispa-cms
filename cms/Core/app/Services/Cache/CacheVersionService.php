<?php

namespace Cms\Core\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Throwable;

class CacheVersionService
{
    private const VERSION_KEY_PREFIX = 'cms:cache-version:';

    /** @var array<string, int> */
    private array $versionMemo = [];

    public function versionedKey(string $baseKey, array|string $scopes): string
    {
        $scopes = $this->normalizeScopes($scopes);

        if ($scopes === []) {
            return $baseKey;
        }

        $versions = [];
        foreach ($scopes as $scope) {
            $versions[] = $scope . '=' . $this->version($scope);
        }

        return $baseKey . ':v:' . substr(sha1(implode('|', $versions)), 0, 16);
    }

    public function version(string $scope): int
    {
        if (array_key_exists($scope, $this->versionMemo)) {
            return $this->versionMemo[$scope];
        }

        return $this->versionMemo[$scope] = max(1, (int) Cache::get($this->versionKey($scope), 1));
    }

    public function bump(string $scope): int
    {
        $key = $this->versionKey($scope);

        try {
            if (!Cache::has($key)) {
                Cache::forever($key, 1);
            }

            $next = Cache::increment($key);
            if (is_numeric($next) && (int) $next > 1) {
                return $this->versionMemo[$scope] = (int) $next;
            }
        } catch (Throwable) {
            // Some cache stores do not support atomic increment. Fall back below.
        }

        $next = $this->version($scope) + 1;
        Cache::forever($key, $next);
        $this->versionMemo[$scope] = $next;

        return $next;
    }

    /**
     * @param  array<int, string>|string  $scopes
     * @return array<string, int>
     */
    public function bumpMany(array|string $scopes): array
    {
        $bumped = [];

        foreach ($this->normalizeScopes($scopes) as $scope) {
            $bumped[$scope] = $this->bump($scope);
        }

        return $bumped;
    }

    private function versionKey(string $scope): string
    {
        return self::VERSION_KEY_PREFIX . sha1($scope);
    }

    /**
     * @param  array<int, string>|string  $scopes
     * @return array<int, string>
     */
    private function normalizeScopes(array|string $scopes): array
    {
        $scopes = is_array($scopes) ? $scopes : [$scopes];

        $scopes = array_values(array_unique(array_filter(array_map(
            static fn ($scope): string => trim((string) $scope),
            $scopes
        ))));

        sort($scopes, SORT_NATURAL);

        return $scopes;
    }
}
