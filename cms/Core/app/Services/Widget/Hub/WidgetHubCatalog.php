<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Models\Widget;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Support\Widget\WidgetChangeNotes;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Support\Facades\Cache;

/**
 * Site-side reader of the widget hub (`/api/v2/public/platform/widgets...`, platform hub Task 4) through Core's signed
 * {@see PlatformHubClient}, resolved from the container on each call.
 *
 * The catalog is cached 10 minutes per site (key includes app.url); install state (`installed_widget_id`, `installed_version`), `missing_resolvers`
 * and `preview_urls` are added on every read, so they follow the site's database and URL. Preview images are proxied
 * through {@see previewImage()} and cached 24 hours per site in the app cache (not the public disk: what a site may
 * see on the hub stays behind `widget.hub`). Any hub failure is {@see WidgetHubUnreachable}, except
 * a 404 on one widget, which is {@see WidgetHubException::hubNotFound()}.
 */
class WidgetHubCatalog
{
    public const PATH = '/api/v2/public/platform/widgets';

    public const CACHE_KEY = 'widget-hub:catalog';

    public const TTL_SECONDS = 600;

    public const PREVIEW_CACHE_KEY = 'widget-hub:preview';

    public const PREVIEW_TTL_SECONDS = 86400;

    /** Site route that proxies one hub preview image ({@see previewImage()}). */
    public const PREVIEW_ROUTE = 'api.v2.widget-hub.preview';

    /** Same shape {@see WidgetBundle} accepts for widget_key. */
    public const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/';

    public function __construct(private readonly WidgetResolverInventory $inventory)
    {
    }

    /**
     * Catalog entries visible to this site: `widget_key, publisher, name, description, latest_version, content_sha256,
     * requires, preview_theme_keys, name_translations, description_translations` (null when the hub sends none) plus
     * `display_name` / `display_description` (in the request locale, falling back to English then `name`), `installed_widget_id`, `installed_version`, `missing_resolvers`
     * (requirement keys this site cannot serve) and `preview_urls` (theme_key => this site's proxy URL of the
     * latest version's preview image; `{}` when there are none) and `changes` (what-changed notes of the hub versions
     * above `installed_version`, or of every version when not installed, newest first:
     * `[{version: int, items: [{type: added|changed|fixed, text}]}]` in the admin's language, falling back to
     * Vietnamese then English; `[]` from a hub that sends none).
     *
     * @return list<array<string, mixed>>
     *
     * @throws WidgetHubUnreachable
     */
    public function list(bool $refresh = false): array
    {
        $entries = $refresh ? null : Cache::get(self::cacheKey());
        if (!is_array($entries)) {
            $entries = $this->fetchCatalog();
            Cache::put(self::cacheKey(), $entries, self::TTL_SECONDS);
        }

        $installed = Widget::query()->whereIn('widget_key', array_column($entries, 'widget_key'))
            ->get(['id', 'widget_key', 'version'])->keyBy('widget_key');

        $locale = WidgetNameTranslations::displayLocale();

        return array_map(function (array $entry) use ($installed, $locale): array {
            $widget = $installed->get($entry['widget_key']);
            $installedVersion = $widget === null ? null : (int) ($widget->version ?? 1);
            $changes = [];
            // Cached before notes existed: no `changes` key, so none.
            foreach ($entry['changes'] ?? [] as $change) {
                if ($installedVersion === null || $change['version'] > $installedVersion) {
                    $changes[] = ['version' => $change['version'], 'items' => WidgetChangeNotes::localize($change['items'], $locale)];
                }
            }

            $out = $entry + [
                'display_name' => WidgetNameTranslations::resolve($entry['name_translations'] ?? null, $entry['name']),
                'display_description' => $entry['description'] === null && ($entry['description_translations'] ?? null) === null
                    ? null
                    : WidgetNameTranslations::resolve($entry['description_translations'] ?? null, $entry['description']),
                'installed_widget_id' => $widget === null ? null : (int) $widget->id,
                'installed_version' => $installedVersion,
                'missing_resolvers' => array_keys($this->inventory->missing($entry['requires'])),
                'preview_urls' => (object) self::previewUrls($entry),
            ];
            $out['changes'] = $changes;

            return $out;
        }, $entries);
    }

    /**
     * Latest hub version per widget key from the cached catalog only (never calls the hub); null when not cached.
     *
     * @return array<string, int>|null
     */
    public function cachedLatestVersions(): ?array
    {
        $entries = Cache::get(self::cacheKey());
        if (!is_array($entries)) {
            return null;
        }

        return array_column($entries, 'latest_version', 'widget_key');
    }

    /**
     * Highest version the hub serves for one widget.
     *
     * @throws WidgetHubException|WidgetHubUnreachable
     */
    public function latestVersion(string $widgetKey): int
    {
        $versions = $this->widgetGet($widgetKey, '/versions')['versions'] ?? null;
        if (!is_array($versions)) {
            throw new WidgetHubUnreachable();
        }
        $numbers = array_filter(array_map(static fn ($v): mixed => is_array($v) ? ($v['version'] ?? null) : null, $versions), 'is_int');
        if ($numbers === []) {
            throw WidgetHubException::hubNotFound();
        }

        return max($numbers);
    }

    /**
     * The signed bundle (sig.json) of one version, not verified yet.
     *
     * @throws WidgetHubException|WidgetHubUnreachable
     */
    public function signedBundle(string $widgetKey, int $version): string
    {
        $sig = $this->widgetGet($widgetKey, '/versions/' . $version)['sig_json'] ?? null;
        if (!is_string($sig) || $sig === '') {
            throw new WidgetHubUnreachable();
        }

        return $sig;
    }

    /**
     * PNG bytes of one design's preview image as published with a hub version, cached 24 hours per site. The hub
     * answer must be a real PNG (signature and image header) of at most {@see WidgetBundle::PREVIEW_MAX_BYTES}.
     *
     * @throws WidgetHubException|WidgetHubUnreachable 404 for a malformed key/theme or a hub 404; anything else unreachable
     */
    public function previewImage(string $widgetKey, int $version, string $themeKey): string
    {
        if (preg_match(self::KEY_PATTERN, $widgetKey) !== 1 || preg_match(self::KEY_PATTERN, $themeKey) !== 1 || $version < 1) {
            throw WidgetHubException::hubNotFound();
        }
        $cacheKey = self::PREVIEW_CACHE_KEY . ':' . sha1((string) config('app.url', '')) . ':' . sha1($widgetKey . '|' . $version . '|' . $themeKey);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $bytes = (string) ($this->client()->getBinary(self::PATH . '/' . $widgetKey . '/previews/' . $version . '/' . $themeKey)['body'] ?? '');
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
                throw WidgetHubException::hubNotFound();
            }
            throw new WidgetHubUnreachable($e);
        }
        if (!self::isPng($bytes)) {
            throw new WidgetHubUnreachable();
        }

        Cache::put($cacheKey, $bytes, self::PREVIEW_TTL_SECONDS);

        return $bytes;
    }

    private static function isPng(string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > WidgetBundle::PREVIEW_MAX_BYTES || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return false;
        }
        $info = @getimagesizefromstring($bytes);

        return is_array($info) && ($info[2] ?? null) === IMAGETYPE_PNG && $info[0] > 0 && $info[1] > 0;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, string>
     */
    private static function previewUrls(array $entry): array
    {
        $urls = [];
        foreach ($entry['preview_theme_keys'] as $themeKey) {
            if (preg_match(self::KEY_PATTERN, $themeKey) === 1) {
                $urls[$themeKey] = route(self::PREVIEW_ROUTE, [
                    'widgetKey' => $entry['widget_key'], 'version' => $entry['latest_version'], 'themeKey' => $themeKey,
                ]);
            }
        }

        return $urls;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function widgetGet(string $widgetKey, string $suffix): array
    {
        if (preg_match(self::KEY_PATTERN, $widgetKey) !== 1) {
            throw WidgetHubException::hubNotFound();
        }
        try {
            return $this->client()->get(self::PATH . '/' . $widgetKey . $suffix);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
                throw WidgetHubException::hubNotFound();
            }
            throw new WidgetHubUnreachable($e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCatalog(): array
    {
        try {
            $payload = $this->client()->get(self::PATH);
        } catch (PlatformHubException $e) {
            throw new WidgetHubUnreachable($e);
        }
        if (!is_array($payload['widgets'] ?? null)) {
            throw new WidgetHubUnreachable();
        }

        $entries = [];
        foreach ($payload['widgets'] as $row) {
            $entry = self::entry($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * One catalog row with only known fields and checked types; null for a malformed row.
     *
     * @return array<string, mixed>|null
     */
    private static function entry(mixed $row): ?array
    {
        if (!is_array($row)
            || !is_string($row['widget_key'] ?? null) || preg_match(self::KEY_PATTERN, $row['widget_key']) !== 1
            || !is_int($row['latest_version'] ?? null) || $row['latest_version'] < 1
            || !is_string($row['name'] ?? null)) {
            return null;
        }
        $requires = is_array($row['requires'] ?? null) ? $row['requires'] : [];
        $resolvers = [];
        foreach ((array) ($requires['resolvers'] ?? []) as $key => $version) {
            if (is_string($key) && is_int($version)) {
                $resolvers[$key] = $version;
            }
        }

        return [
            'widget_key' => $row['widget_key'],
            'publisher' => is_string($row['publisher'] ?? null) ? $row['publisher'] : null,
            'name' => mb_substr($row['name'], 0, 255),
            'description' => is_string($row['description'] ?? null) ? mb_substr($row['description'], 0, 2000) : null,
            'latest_version' => $row['latest_version'],
            'content_sha256' => is_string($row['content_sha256'] ?? null) ? $row['content_sha256'] : null,
            'requires' => ['renderer' => (int) ($requires['renderer'] ?? 1), 'resolvers' => $resolvers],
            'preview_theme_keys' => array_values(array_filter((array) ($row['preview_theme_keys'] ?? []), 'is_string')),
            'name_translations' => self::translations($row['name_translations'] ?? null, WidgetNameTranslations::NAME_MAX),
            'description_translations' => self::translations($row['description_translations'] ?? null, WidgetNameTranslations::DESCRIPTION_MAX),
            'changes' => self::changes($row['changes'] ?? null),
        ];
    }

    /**
     * What-changed notes from the hub, newest first: `[{version, items: [{type, vi, en}]}]`; malformed versions and
     * items are dropped, an older hub without the field gives `[]`.
     *
     * @return list<array{version: int, items: list<array{type: string, vi: string, en: string}>}>
     */
    private static function changes(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $change) {
            if (!is_array($change) || !is_int($change['version'] ?? null) || $change['version'] < 1) {
                continue;
            }
            $items = WidgetChangeNotes::lenientItems($change['items'] ?? null);
            if ($items !== []) {
                $out[$change['version']] = ['version' => $change['version'], 'items' => $items];
            }
        }
        krsort($out);

        return array_values($out);
    }

    /**
     * Per-language names from the hub in normal form, each cut to `$max` characters; null when there are none.
     *
     * @return array<string, string>|null
     */
    private static function translations(mixed $value, int $max): ?array
    {
        $normal = WidgetNameTranslations::normalize(is_array($value) ? $value : null);

        return $normal === null ? null : array_map(static fn (string $text): string => mb_substr($text, 0, $max), $normal);
    }

    /** Per site, so sites sharing one cache store never see each other's catalog. */
    private static function cacheKey(): string
    {
        return self::CACHE_KEY . ':' . sha1((string) config('app.url', ''));
    }

    private function client(): PlatformHubClient
    {
        return app(PlatformHubClient::class);
    }
}
