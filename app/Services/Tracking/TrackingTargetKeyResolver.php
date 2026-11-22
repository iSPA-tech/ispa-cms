<?php

namespace App\Services\Tracking;

use App\Events\SaveTracking;
use Illuminate\Support\Str;

class TrackingTargetKeyResolver
{
    private const TARGET_KEY_PATTERN = '/^[a-z0-9][a-z0-9._:-]{0,190}$/';

    /**
     * Resolve request context into a stable, privacy-safe target.
     *
     * @return array{
     *     target_key: string,
     *     target_kind: string,
     *     display_label: null,
     *     public_path: string|null,
     *     is_rankable: bool
     * }|null
     */
    public function resolve(
        ?string $targetType,
        int $targetId,
        ?string $routeName,
        array $routeParameters,
        ?string $url
    ): ?array {
        $entity = $this->resolveEntity($targetType, $targetId);
        if ($entity !== null) {
            return $entity;
        }

        if ($this->isUnsafeUrlContext($url)) {
            return null;
        }

        $normalizedRouteName = $this->normalizeKeySegment($routeName);
        if ($normalizedRouteName !== null && $this->isExcludedRoute($normalizedRouteName)) {
            return null;
        }

        $route = $this->resolveRoute($routeName, $routeParameters);
        if ($route !== null) {
            return $route;
        }

        return $this->resolvePublicPath($url);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveEvent(SaveTracking $event): ?array
    {
        return $this->resolve(
            $event->type,
            $event->id,
            $event->routeName,
            $event->routeParameters,
            $event->url
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveEntity(?string $targetType, int $targetId): ?array
    {
        if ($targetType === null || $targetId <= 0) {
            return null;
        }

        $aliases = (array) config('tracking.target_aliases', []);
        $alias = $aliases[$targetType] ?? null;
        if (!is_string($alias)) {
            return null;
        }

        $alias = $this->normalizeKeySegment($alias);
        $targetKey = $alias !== null ? "{$alias}:{$targetId}" : null;

        if (!$this->isValidTargetKey($targetKey)) {
            return null;
        }

        return $this->target($targetKey, 'entity', null, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRoute(?string $routeName, array $routeParameters): ?array
    {
        $routeName = $this->normalizeKeySegment($routeName);
        if ($routeName === null || $this->isExcludedRoute($routeName)) {
            return null;
        }

        $definitions = (array) config('tracking.dynamic_route_resolvers', []);
        $definition = $definitions[$routeName] ?? data_get($definitions, $routeName);
        if (!is_array($definition)) {
            if ($routeParameters !== []) {
                return null;
            }

            $targetKey = "route:{$routeName}";

            return $this->isValidTargetKey($targetKey)
                ? $this->target($targetKey, 'route', null, true)
                : null;
        }

        $identity = array_values((array) ($definition['identity_parameters'] ?? []));
        $optional = array_values((array) ($definition['optional_identity_parameters'] ?? []));
        $presentation = array_values((array) ($definition['presentation_parameters'] ?? []));
        $knownParameters = array_unique([...$identity, ...$optional, ...$presentation]);

        if (array_diff(array_keys($routeParameters), $knownParameters) !== []) {
            return null;
        }

        $segments = ['route', $routeName];
        foreach ($identity as $parameter) {
            if (!array_key_exists($parameter, $routeParameters)) {
                return null;
            }

            $value = $this->normalizeParameter($routeParameters[$parameter]);
            $parameter = $this->normalizeKeySegment((string) $parameter);
            if ($parameter === null || $value === null) {
                return null;
            }

            $segments[] = $parameter;
            $segments[] = $value;
        }

        foreach ($optional as $parameter) {
            if (!array_key_exists($parameter, $routeParameters)) {
                continue;
            }

            $value = $this->normalizeParameter($routeParameters[$parameter]);
            $parameter = $this->normalizeKeySegment((string) $parameter);
            if ($parameter === null || $value === null) {
                return null;
            }

            $segments[] = $parameter;
            $segments[] = $value;
        }

        $targetKey = implode(':', $segments);

        return $this->isValidTargetKey($targetKey)
            ? $this->target($targetKey, 'route', null, true)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePublicPath(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = Str::lower((string) $parts['host']);
        $publicHosts = array_map(
            static fn (mixed $value): string => Str::lower((string) $value),
            (array) config('tracking.public_hosts', [])
        );
        if (!in_array($host, $publicHosts, true)) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        if (array_intersect(
            array_map(static fn (mixed $key): string => Str::lower((string) $key), array_keys($query)),
            ['signature', 'expires', 'token', 'preview', 'user', 'account']
        ) !== []) {
            return null;
        }

        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if (str_contains($path, "\0") || preg_match('#(?:^|/)\.\.?(?:/|$)#', $path)) {
            return null;
        }

        foreach ((array) config('tracking.excluded_path_prefixes', []) as $prefix) {
            $prefix = '/' . trim((string) $prefix, '/');
            if ($path === $prefix || Str::startsWith($path, "{$prefix}/")) {
                return null;
            }
        }

        $allowed = false;
        foreach ((array) config('tracking.public_path_patterns', []) as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $path) === 1) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return null;
        }

        //  The normalized path rides along as public_path so receipts and the
        //  target catalog stay readable; the hash alone identifies nothing.
        return $this->target(
            'path:sha256:' . hash('sha256', $path),
            'path',
            $path,
            false
        );
    }

    private function isExcludedRoute(string $routeName): bool
    {
        foreach ((array) config('tracking.excluded_route_prefixes', []) as $prefix) {
            $prefix = Str::lower(trim((string) $prefix, '.'));
            if ($routeName === $prefix || Str::startsWith($routeName, "{$prefix}.")) {
                return true;
            }
        }

        return false;
    }

    private function isUnsafeUrlContext(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return true;
        }

        $host = Str::lower((string) $parts['host']);
        $publicHosts = array_map(
            static fn (mixed $value): string => Str::lower((string) $value),
            (array) config('tracking.public_hosts', [])
        );
        if (!in_array($host, $publicHosts, true)) {
            return true;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $queryKeys = array_map(
            static fn (mixed $key): string => Str::lower((string) $key),
            array_keys($query)
        );
        if (array_intersect($queryKeys, ['signature', 'expires', 'token', 'preview', 'user', 'account']) !== []) {
            return true;
        }

        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = '/' . ltrim($path, '/');

        foreach ((array) config('tracking.excluded_path_prefixes', []) as $prefix) {
            $prefix = '/' . trim((string) $prefix, '/');
            if ($path === $prefix || Str::startsWith($path, "{$prefix}/")) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKeySegment(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::lower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value);
        $value = trim((string) $value, '-');

        return $value !== '' ? $value : null;
    }

    private function normalizeParameter(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = Str::lower($value);
        if ($value === $normalized && preg_match('/^[a-z0-9._-]+$/', $normalized) === 1) {
            return $normalized;
        }

        return 'h-' . hash('sha256', $value);
    }

    private function isValidTargetKey(?string $targetKey): bool
    {
        return is_string($targetKey) && preg_match(self::TARGET_KEY_PATTERN, $targetKey) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function target(
        string $targetKey,
        string $targetKind,
        ?string $publicPath,
        bool $isRankable
    ): array {
        return [
            'target_key' => $targetKey,
            'target_kind' => $targetKind,
            'display_label' => null,
            'public_path' => $publicPath,
            'is_rankable' => $isRankable,
        ];
    }
}
