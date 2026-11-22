<?php

declare(strict_types=1);

namespace Cms\Core\Support\Widget;

use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use InvalidArgumentException;
use JsonException;

/**
 * "What changed" notes of hub widget versions.
 *
 * Notes never travel inside the signed bundle ({@see \Cms\Core\Services\Widget\Hub\WidgetBundle} checks its keys
 * strictly and older sites must keep reading new bundles). They are authored in the publisher catalog file
 * `widget-changelogs.php` (see that file's header for the format), written by `cms:widget-bundle-build` as an unsigned
 * sidecar `<bundle>.notes.json`, stored by the hub on the version row and served in the hub catalog.
 *
 * One item: `{"type": "added"|"changed"|"fixed", "vi": "...", "en": "..."}`, both texts trimmed, non-empty, at most
 * {@see self::TEXT_MAX} characters. Sidecar: `{"widget_key": "...", "version": 20261101, "changes": [item, ...]}`.
 */
final class WidgetChangeNotes
{
    public const TYPES = ['added', 'changed', 'fixed'];

    public const TEXT_MAX = 300;

    /** Most items one version may carry. */
    public const MAX_ITEMS = 50;

    /** Changelog file inside a publisher catalog directory. */
    public const FILE = 'widget-changelogs.php';

    /** Sidecar suffix appended to the bundle path. */
    public const SIDECAR_SUFFIX = '.notes.json';

    /**
     * Validated items in normal form (`type`, `vi`, `en`, texts trimmed).
     *
     * @return list<array{type: string, vi: string, en: string}>
     *
     * @throws InvalidArgumentException
     */
    public static function items(mixed $items): array
    {
        if (!is_array($items) || $items === [] || !array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('changes must be a non-empty list of at most ' . self::MAX_ITEMS . ' items');
        }
        $out = [];
        foreach ($items as $i => $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['type', 'vi', 'en']) !== []) {
                throw new InvalidArgumentException('change ' . $i . ' may only have the keys type, vi, en');
            }
            if (!in_array($item['type'] ?? null, self::TYPES, true)) {
                throw new InvalidArgumentException('change ' . $i . ' type must be one of ' . implode(', ', self::TYPES));
            }
            $normal = ['type' => $item['type']];
            foreach (['vi', 'en'] as $locale) {
                $text = $item[$locale] ?? null;
                if (!is_string($text) || trim($text) === '' || mb_strlen(trim($text)) > self::TEXT_MAX) {
                    throw new InvalidArgumentException('change ' . $i . ' ' . $locale . ' must be a non-empty text of at most ' . self::TEXT_MAX . ' characters');
                }
                $normal[$locale] = trim($text);
            }
            $out[] = $normal;
        }

        return $out;
    }

    /**
     * Valid items only, invalid ones dropped (for data read back from the hub); empty list when nothing is valid.
     *
     * @return list<array{type: string, vi: string, en: string}>
     */
    public static function lenientItems(mixed $items): array
    {
        $out = [];
        foreach (is_array($items) && array_is_list($items) ? array_slice($items, 0, self::MAX_ITEMS) : [] as $item) {
            try {
                $out[] = self::items([$item])[0];
            } catch (InvalidArgumentException) {
                // Skip a malformed item; the rest still shows.
            }
        }

        return $out;
    }

    /**
     * The whole validated changelog of a publisher: widget_key => [version => items], or [] when it has no file.
     *
     * @return array<string, array<int, list<array{type: string, vi: string, en: string}>>>
     *
     * @throws InvalidArgumentException
     */
    public static function changelog(string $publisher): array
    {
        $path = rtrim(WidgetCatalogSources::directory($publisher), '/') . '/' . self::FILE;
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new InvalidArgumentException(self::FILE . ' must return an array');
        }
        $out = [];
        foreach ($data as $key => $versions) {
            if (!is_string($key) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/', $key) !== 1 || !is_array($versions)) {
                throw new InvalidArgumentException(self::FILE . ': ' . var_export($key, true) . ' is not a widget key with a version map');
            }
            foreach ($versions as $version => $items) {
                if (!is_int($version) || $version < 1) {
                    throw new InvalidArgumentException(self::FILE . ': ' . $key . ' version ' . var_export($version, true) . ' is not a whole number of at least 1');
                }
                try {
                    $out[$key][$version] = self::items($items);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException(self::FILE . ': ' . $key . ' ' . $version . ': ' . $e->getMessage());
                }
            }
        }

        return $out;
    }

    /**
     * Sidecar JSON text for one version.
     *
     * @param list<array{type: string, vi: string, en: string}> $items
     */
    public static function encodeSidecar(string $widgetKey, int $version, array $items): string
    {
        return json_encode(
            ['widget_key' => $widgetKey, 'version' => $version, 'changes' => self::items($items)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Validated items of a sidecar, which must name exactly this widget key and version.
     *
     * @return list<array{type: string, vi: string, en: string}>
     *
     * @throws InvalidArgumentException
     */
    public static function decodeSidecar(string $json, string $widgetKey, int $version): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('notes file is not valid JSON');
        }
        if (!is_array($data) || ($data['widget_key'] ?? null) !== $widgetKey || ($data['version'] ?? null) !== $version) {
            throw new InvalidArgumentException('notes file is not for ' . $widgetKey . ' version ' . $version);
        }

        return self::items($data['changes'] ?? null);
    }

    /**
     * Items in one language: that language's text, else Vietnamese, else English.
     *
     * @param list<array<string, string>> $items
     * @return list<array{type: string, text: string}>
     */
    public static function localize(array $items, string $locale): array
    {
        $locale = strtolower(substr($locale, 0, 2));

        return array_map(static fn (array $item): array => [
            'type' => $item['type'],
            'text' => $item[$locale] ?? $item['vi'] ?? $item['en'] ?? '',
        ], $items);
    }
}
