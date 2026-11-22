<?php

namespace Cms\Core\Services\Menu;

class MenuItemUrlResolver
{
    public function resolve(mixed $menu, ?string $parentPrefix = null): string
    {
        $type = (string) data_get($menu, 'type', 'url');
        $rawUrl = trim((string) data_get($menu, 'url', ''));
        $prefix = $this->prefix($menu, $parentPrefix);

        if ($type !== 'url') {
            $targetUrl = $this->targetUrl($menu, $type, $prefix);
            if ($targetUrl !== null) {
                return $targetUrl;
            }
        }

        if ($this->isAbsoluteUrl($rawUrl)) {
            return $rawUrl;
        }

        return match ($type) {
            'category' => $this->categoryUrl($rawUrl, $prefix),
            'post' => $this->postUrl($rawUrl, $prefix),
            default => $this->rawUrl($rawUrl, $prefix),
        };
    }

    private function targetUrl(mixed $menu, string $type, string $prefix): ?string
    {
        $target = data_get($menu, 'target');
        if ($target === null) {
            return null;
        }

        $slug = trim((string) data_get($target, 'slug', ''));
        if ($slug === '') {
            return null;
        }

        if ($prefix !== '') {
            return url($this->joinPath($prefix, $slug));
        }

        $route = trim((string) data_get($target, 'route', ''));
        if ($route !== '') {
            return $route;
        }

        return match ($type) {
            'category' => route('category.' . lang('path', 'vi'), ['slug' => $slug]),
            'post' => route('reader', ['slug' => $slug]),
            default => url($slug),
        };
    }

    private function categoryUrl(string $rawUrl, string $prefix): string
    {
        $path = $this->relativePath($rawUrl);

        if ($prefix !== '') {
            return url($this->joinPath($prefix, $path));
        }

        return route('category.' . lang('path', 'vi'), ['slug' => $path]);
    }

    private function postUrl(string $rawUrl, string $prefix): string
    {
        $path = $this->relativePath($rawUrl);

        if ($prefix !== '') {
            return url($this->joinPath($prefix, $path));
        }

        return route('reader', ['slug' => $path]);
    }

    private function rawUrl(string $rawUrl, string $prefix): string
    {
        $path = $this->relativePath($rawUrl);

        if ($prefix !== '') {
            $path = $this->joinPath($prefix, $path);
        }

        return url($path);
    }

    private function prefix(mixed $menu, ?string $parentPrefix): string
    {
        $custom = data_get($menu, 'custom', []);
        if (is_string($custom)) {
            $decoded = json_decode($custom, true);
            $custom = is_array($decoded) ? $decoded : [];
        }

        return $this->relativePath($parentPrefix ?? (data_get($custom, 'prefix', '') ?? ''));
    }

    private function relativePath(string $value): string
    {
        return trim($value, '/');
    }

    private function joinPath(string ...$segments): string
    {
        return collect($segments)
            ->map(fn (string $segment): string => trim($segment, '/'))
            ->filter(fn (string $segment): bool => $segment !== '')
            ->implode('/');
    }

    private function isAbsoluteUrl(string $url): bool
    {
        return str_contains($url, '://') || str_starts_with($url, '//');
    }
}
