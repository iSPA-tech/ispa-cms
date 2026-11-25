<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Store listings of hub modules for the site admin: the full listing of one module, cached 10 minutes, and
 * its images kept in a local cache.
 *
 * The hub answer (`GET /api/v2/public/platform/listings/{name}`, signed like every hub answer) is validated field by
 * field (strings with length caps, https-only links, publisher type from the known set), so the admin never renders
 * unchecked hub text. Images never reach the browser by hub token: each token is replaced by a local hash
 * (`sha256(token)`) and remembered as "hash -> module + token" when a listing or the catalog is validated. A hash that
 * no fetched listing ever produced is unknown, and so is a file that is not a PNG, WebP or JPEG. The bytes are fetched
 * once from `GET /api/v2/public/platform/listing-media/{token}` through PlatformHubClient and stored under
 * `storage/app/module-store-media/<hash>`; the content type comes from the bytes, never from the hub header.
 *
 * A hub that predates the listings (the endpoint answers 404, or the catalog has no `listing` key) is not an error:
 * `listing()` then answers `available = false` so the store tab keeps working on the catalog alone. Every other hub
 * failure is thrown and never cached.
 */
final class ModuleListingClient
{
    /** Hub path prefix of the full listing. */
    public const LISTING_PATH = '/api/v2/public/platform/listings/';

    /** Hub path prefix of listing images. */
    public const MEDIA_PATH = '/api/v2/public/platform/listing-media/';

    /** Folder (below storage/app) of the local image cache. */
    public const MEDIA_DIR = 'module-store-media';

    /** Cache lifetime of a listing in seconds. */
    public const TTL_SECONDS = 600;

    /** How long a "hash -> token" entry lives, in seconds. */
    public const MAP_TTL_SECONDS = 86400;

    /** Largest image taken from the hub, in bytes. */
    public const MAX_MEDIA_BYTES = 5242880;

    /** Publisher types a listing may carry. */
    public const PUBLISHER_TYPES = ['ispa', 'partner', 'community'];

    /** Locales of listing content. */
    private const LOCALES = ['vi', 'en'];

    /** @var (Closure(): PlatformHubClient)|null */
    private ?Closure $clientFactory;

    /**
     * @param (callable(): PlatformHubClient)|null $clientFactory builds the hub client lazily (default: from config)
     * @param string|null                          $mediaDirectory local image cache folder (default: storage/app/module-store-media)
     */
    public function __construct(?callable $clientFactory = null, private readonly ?string $mediaDirectory = null)
    {
        $this->clientFactory = $clientFactory !== null ? Closure::fromCallable($clientFactory) : null;
    }

    /**
     * The validated full listing of one module.
     *
     * Shape: `{name, available, listing, installs, last_updated_at, versions[]}`; `listing` is null when the hub has
     * none live, `available` is false when the hub predates listings (or does not show the module). Each version:
     * `version`, `revoked`, `published_at`, `sheet` (array|null) and `release_notes` (string|null).
     *
     * @return array<string, mixed>
     *
     * @throws PlatformHubException any hub failure other than "no such listing"
     */
    public function listing(string $name): array
    {
        $key = $this->cacheKey($name);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $client = $this->clientFactory !== null ? ($this->clientFactory)() : new PlatformHubClient();
        try {
            $payload = $client->get(self::LISTING_PATH . $name);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
                return $this->unavailable($name);
            }
            throw $e;
        }

        $listing = $this->validated($name, $payload);
        Cache::put($key, $listing, self::TTL_SECONDS);

        return $listing;
    }

    /**
     * Release notes the site has not installed yet, newest first.
     *
     * Versions above the installed one (or, for a module that is not installed, only the newest) that are not revoked
     * and carry notes.
     *
     * @param list<array<string, mixed>> $versions the `versions` of {@see listing()}
     *
     * @return list<array{version: string, published_at: ?string, notes: string}>
     */
    public static function releaseNotesAbove(array $versions, ?string $installed): array
    {
        $eligible = array_values(array_filter($versions, static fn (array $v): bool => ! $v['revoked']));
        usort($eligible, static fn (array $a, array $b): int => ModuleVersionConstraint::compare((string) $b['version'], (string) $a['version']));
        if ($installed === null || preg_match('/^\d+(?:\.\d+)*$/', $installed) !== 1) {
            $eligible = array_slice($eligible, 0, 1);
        } else {
            $eligible = array_values(array_filter($eligible, static fn (array $v): bool => ModuleVersionConstraint::compare((string) $v['version'], $installed) > 0));
        }

        $notes = [];
        foreach ($eligible as $v) {
            if (is_string($v['release_notes'] ?? null) && $v['release_notes'] !== '') {
                $notes[] = ['version' => (string) $v['version'], 'published_at' => $v['published_at'], 'notes' => $v['release_notes']];
            }
        }

        return $notes;
    }

    /**
     * Local file and content type of a cached image, fetching it from the hub the first time.
     *
     * Null for a hash no fetched listing produced, for a hub that does not serve the image, and for bytes that are not
     * a PNG, WebP or JPEG within the size cap.
     *
     * @return array{path: string, type: string}|null
     *
     * @throws PlatformHubException for a hub failure other than "no such image"
     */
    public function media(string $hash): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }
        $known = Cache::get(self::mapKey($hash));
        if (! is_array($known) || ! is_string($known['token'] ?? null) || self::hashOf($known['token']) !== $hash) {
            return null;
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . $hash;
        if (is_file($path)) {
            $type = self::sniff((string) @file_get_contents($path));
            if ($type !== null) {
                return ['path' => $path, 'type' => $type];
            }
            @unlink($path);
        }

        $client = $this->clientFactory !== null ? ($this->clientFactory)() : new PlatformHubClient();
        try {
            $answer = $client->getBinary(self::MEDIA_PATH . $known['token']);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
                return null;
            }
            throw $e;
        }

        $bytes = $answer['body'];
        $type = strlen($bytes) <= self::MAX_MEDIA_BYTES ? self::sniff($bytes) : null;
        if ($type === null || ! $this->store($path, $bytes)) {
            return null;
        }

        return ['path' => $path, 'type' => $type];
    }

    /**
     * Validate a catalog `listing` summary and normalise it; null when absent or not an object.
     *
     * Result: `{category, publisher: {key, type, name}|null, icon_hash, content: {vi|en: {display_name, summary}},
     * installs: ?int, rating: {average: ?float, count: int}}` (`content.<locale>.price_note` carries the price note per language;
     * `rating` from {@see ModuleCommunityClient::aggregate},). The icon token is replaced by its local hash and remembered for
     * {@see media()}.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(string $module, mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return [
            'category' => ListingCategories::normalize($raw['category'] ?? null),
            'publisher' => self::publisher($raw['publisher'] ?? null),
            'icon_hash' => self::mediaHash($module, $raw['icon_token'] ?? null),
            'content' => self::content($raw['content'] ?? null, ['display_name' => 120, 'summary' => 300, 'price_note' => 200]),
            // install count (public modules only; null from a hub that predates it) and price note for the cards.
            'installs' => is_int($raw['installs'] ?? null) && $raw['installs'] >= 0 ? $raw['installs'] : null,
            // average stars and count of visible ratings; {null, 0} from a hub that predates them.
            'rating' => ModuleCommunityClient::aggregate($raw['rating'] ?? null),
        ];
    }

    /**
     * The local hash of a hub media token.
     */
    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Validate a hub listing answer and normalise it.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validated(string $name, array $payload): array
    {
        $listing = null;
        $raw = $payload['listing'] ?? null;
        if (is_array($raw)) {
            $screenshots = [];
            foreach (array_slice(array_values(array_filter((array) ($raw['screenshots'] ?? []), 'is_array')), 0, 12) as $shot) {
                $hash = self::mediaHash($name, $shot['token'] ?? null);
                if ($hash !== null) {
                    $screenshots[] = [
                        'hash' => $hash,
                        'kind' => self::text($shot['kind'] ?? null, 32) ?? '',
                        'caption_vi' => self::text($shot['caption_vi'] ?? null, 200),
                        'caption_en' => self::text($shot['caption_en'] ?? null, 200),
                    ];
                }
            }

            $listing = (self::summary($name, $raw) ?? []) + [
                'content' => [],
                'revision' => is_int($raw['revision'] ?? null) ? $raw['revision'] : null,
                'published_at' => self::text($raw['published_at'] ?? null, 40),
                'screenshots' => $screenshots,
                'video_url' => self::url($raw['video_url'] ?? null),
                'demo_url' => self::url($raw['demo_url'] ?? null),
                'support_contact' => self::text($raw['support_contact'] ?? null, 200),
            ];
            // The full answer carries the install count at the top level, not inside the listing.
            unset($listing['installs']);
            $listing['content'] = self::content($raw['content'] ?? null, [
                'display_name' => 120, 'summary' => 300, 'description' => 20000, 'features' => 0, 'faq' => 0, 'price_note' => 200,
            ]);
        }

        $versions = [];
        foreach (array_slice(array_values(array_filter((array) ($payload['versions'] ?? []), 'is_array')), 0, 200) as $version) {
            $number = $version['version'] ?? null;
            if (! is_string($number) || preg_match('/^\d+\.\d+\.\d+$/', $number) !== 1) {
                continue;
            }
            $versions[] = [
                'version' => $number,
                'revoked' => (bool) ($version['revoked'] ?? false),
                'published_at' => self::text($version['published_at'] ?? null, 40),
                'sheet' => self::sheet($version['sheet'] ?? null),
                'release_notes' => self::text($version['release_notes'] ?? null, 20000),
            ];
        }

        return [
            'name' => $name,
            'available' => true,
            'listing' => $listing,
            'installs' => is_int($payload['installs'] ?? null) && $payload['installs'] >= 0 ? $payload['installs'] : null,
            'last_updated_at' => self::text($payload['last_updated_at'] ?? null, 40),
            'versions' => $versions,
        ];
    }

    /**
     * The answer for a hub without listings.
     *
     * @return array<string, mixed>
     */
    private function unavailable(string $name): array
    {
        return ['name' => $name, 'available' => false, 'listing' => null, 'installs' => null, 'last_updated_at' => null, 'versions' => []];
    }

    /**
     * Normalise a technical sheet (build fields only); null when absent.
     *
     * @return array<string, mixed>|null
     */
    private static function sheet(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $database = is_array($raw['database'] ?? null) ? $raw['database'] : [];
        $admin = is_array($raw['admin'] ?? null) ? $raw['admin'] : [];
        $routes = is_array($raw['routes'] ?? null) ? $raw['routes'] : [];
        $size = is_array($raw['size'] ?? null) ? $raw['size'] : [];
        $irreversible = $database['irreversible'] ?? false;
        $requires = [];
        foreach (is_array($raw['requires_modules'] ?? null) ? $raw['requires_modules'] : [] as $module => $range) {
            if (is_string($module) && is_string($range) && strlen($module) <= 64 && strlen($range) <= 64) {
                $requires[$module] = $range;
            }
        }

        return [
            'core_range' => self::text($raw['core_range'] ?? null, 64),
            'requires_modules' => $requires,
            'php_min' => self::text($raw['php_min'] ?? null, 32),
            'database' => [
                'migrations' => self::count($database['migrations'] ?? 0),
                'tables_created' => self::strings($database['tables_created'] ?? [], 128, 100),
                'tables_altered' => self::strings($database['tables_altered'] ?? [], 128, 100),
                'irreversible' => is_array($irreversible) ? self::strings($irreversible, 255, 100) : (bool) $irreversible,
            ],
            'admin' => [
                'permissions' => self::strings($admin['permissions'] ?? [], 128, 200),
                'menus' => self::count($admin['menus'] ?? 0),
            ],
            'routes' => [
                'public' => self::count($routes['public'] ?? 0),
                'admin' => self::count($routes['admin'] ?? 0),
                'api' => self::count($routes['api'] ?? 0),
            ],
            'size' => [
                'zip_bytes' => self::count($size['zip_bytes'] ?? 0),
                'unpacked_bytes' => is_int($size['unpacked_bytes'] ?? null) && $size['unpacked_bytes'] >= 0 ? $size['unpacked_bytes'] : null,
                'files' => self::count($size['files'] ?? 0),
            ],
        ];
    }

    /**
     * Per-locale content with capped strings. A cap of 0 marks the list fields (`features`, `faq`).
     *
     * @param array<string, int> $fields field name => maximum length (0 for features and faq)
     *
     * @return array<string, array<string, mixed>>
     */
    private static function content(mixed $raw, array $fields): array
    {
        $raw = is_array($raw) ? $raw : [];
        $content = [];
        foreach (self::LOCALES as $locale) {
            $block = is_array($raw[$locale] ?? null) ? $raw[$locale] : [];
            $out = [];
            foreach ($fields as $field => $cap) {
                $out[$field] = match ($field) {
                    'features' => self::strings($block['features'] ?? [], 300, 30),
                    'faq' => self::faq($block['faq'] ?? []),
                    default => self::text($block[$field] ?? null, $cap) ?? '',
                };
            }
            $content[$locale] = $out;
        }

        return $content;
    }

    /**
     * Question and answer pairs, capped.
     *
     * @return list<array{q: string, a: string}>
     */
    private static function faq(mixed $raw): array
    {
        $items = [];
        foreach (array_slice(array_values(array_filter((array) $raw, 'is_array')), 0, 30) as $item) {
            $q = self::text($item['q'] ?? null, 300);
            $a = self::text($item['a'] ?? null, 2000);
            if ($q !== null && $a !== null) {
                $items[] = ['q' => $q, 'a' => $a];
            }
        }

        return $items;
    }

    /**
     * Publisher `{key, type, name}` with the type from the known set; null otherwise.
     *
     * @return array{key: string, type: string, name: string}|null
     */
    private static function publisher(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $key = self::text($raw['key'] ?? null, 64);
        $name = self::text($raw['name'] ?? null, 120);
        $type = $raw['type'] ?? null;
        if ($key === null || $name === null || ! is_string($type) || ! in_array($type, self::PUBLISHER_TYPES, true)) {
            return null;
        }

        return ['key' => $key, 'type' => $type, 'name' => $name];
    }

    /**
     * Local hash of a media token that belongs to the module; the pair is remembered for {@see media()}.
     */
    private static function mediaHash(string $module, mixed $token): ?string
    {
        if (! is_string($token) || preg_match('/^([A-Z][A-Za-z0-9]{1,63})\.[a-f0-9]{48}$/', $token, $m) !== 1 || $m[1] !== $module) {
            return null;
        }
        $hash = self::hashOf($token);
        Cache::put(self::mapKey($hash), ['module' => $module, 'token' => $token], self::MAP_TTL_SECONDS);

        return $hash;
    }

    /**
     * Image type from the first bytes: png, webp or jpeg; null for anything else.
     */
    private static function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * Write a file through a temporary name so a reader never sees half an image.
     */
    private function store(string $path, string $bytes): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (@file_put_contents($tmp, $bytes) === false || ! @rename($tmp, $path)) {
                @unlink($tmp);

                return false;
            }
        } catch (Throwable) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Folder of the local image cache.
     */
    private function directory(): string
    {
        return rtrim($this->mediaDirectory ?? storage_path('app/' . self::MEDIA_DIR), '/');
    }

    /**
     * A trimmed string within $max characters, or null (empty, not a string, too long).
     */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }

    /**
     * An https link within 500 characters; null otherwise.
     */
    private static function url(mixed $value): ?string
    {
        $url = self::text($value, 500);

        return $url !== null && preg_match('#^https://[^\s<>"\']+$#i', $url) === 1 ? $url : null;
    }

    /**
     * Non-negative integer, 0 otherwise.
     */
    private static function count(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * A list of valid strings (invalid or too long entries dropped), at most $limit.
     *
     * @return list<string>
     */
    private static function strings(mixed $value, int $max, int $limit): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $text = self::text($item, $max);
            if ($text !== null) {
                $out[] = $text;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Cache key of a module listing, unique per site (domain and hub URL).
     */
    private function cacheKey(string $name): string
    {
        return 'platform:module-listing:v1:' . sha1(
            (string) config('ispa-cms.cms_domain', '') . '|' . (string) config('ispa-cms.platform_hub_url', '')
        ) . ':' . $name;
    }

    /**
     * Cache key of a "hash -> module + token" entry.
     */
    private static function mapKey(string $hash): string
    {
        return 'platform:module-store-media:v1:' . $hash;
    }
}
