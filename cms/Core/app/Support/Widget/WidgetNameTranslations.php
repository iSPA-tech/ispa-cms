<?php

declare(strict_types=1);

namespace Cms\Core\Support\Widget;

/**
 * Per-language names of widgets and designs: `{"vi": "...", "en": "..."}` next to the
 * canonical `name`, which stays the fallback and is never null.
 *
 * One normal form everywhere (database, catalog files, bundles): only the {@see self::LOCALES} keys, trimmed non-empty
 * strings, keys sorted; no entry at all is `null`, never `{}`.
 */
final class WidgetNameTranslations
{
    /** Languages a widget or design name can be given in. */
    public const LOCALES = ['en', 'vi'];

    /** Longest name (widgets.name / widget_themes.name are varchar 255). */
    public const NAME_MAX = 255;

    /** Longest description, as the widget form accepts. */
    public const DESCRIPTION_MAX = 500;

    /**
     * Normal form of a translations value (array or JSON string): unknown keys, non-strings and blank strings are
     * dropped, strings trimmed; null when nothing is left.
     *
     * @return array<string, string>|null
     */
    public static function normalize(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach (self::LOCALES as $locale) {
            $text = $value[$locale] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $out[$locale] = trim($text);
            }
        }

        return $out === [] ? null : $out;
    }

    /** JSON text of the normal form for query-builder writes; null when there is none. */
    public static function encode(mixed $value): ?string
    {
        $normal = self::normalize($value);

        return $normal === null ? null : json_encode($normal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Request header the admin sends with its own language, for widget and design names only. */
    public const ADMIN_LOCALE_HEADER = 'X-Admin-Locale';

    /**
     * Language to show widget and design names in: the admin's language from {@see self::ADMIN_LOCALE_HEADER} when it is
     * one of {@see self::LOCALES}, else the app locale. Only names follow it; the app locale (content filters,
     * validation, mails) is never changed by it.
     */
    public static function displayLocale(): string
    {
        $request = app()->bound('request') ? request() : null;
        $header = strtolower(trim((string) $request?->header(self::ADMIN_LOCALE_HEADER, '')));

        return in_array($header, self::LOCALES, true) ? $header : (string) app()->getLocale();
    }

    /**
     * The name to show in `$locale` (default: {@see self::displayLocale()}): that language, else English, else `$fallback`.
     */
    public static function resolve(mixed $translations, ?string $fallback, ?string $locale = null): string
    {
        $normal = self::normalize($translations) ?? [];
        $locale = strtolower(substr((string) ($locale ?? self::displayLocale()), 0, 2));

        return $normal[$locale] ?? $normal['en'] ?? (string) $fallback;
    }

    /**
     * Validation rules for a translations field at `$attribute` (e.g. `name_translations`, `template.*.name_translations`).
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $attribute, int $max = self::NAME_MAX): array
    {
        $rules = [$attribute => ['nullable', 'array:' . implode(',', self::LOCALES)]];
        foreach (self::LOCALES as $locale) {
            $rules[$attribute . '.' . $locale] = ['nullable', 'string', 'max:' . $max];
        }

        return $rules;
    }

    /**
     * Strict check for signed bundles and catalog files: a non-empty map of known locales to trimmed, non-empty strings
     * of at most `$max` characters, keys sorted. Returns the reason it is not, or null.
     */
    public static function problem(mixed $value, int $max = self::NAME_MAX): ?string
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return 'must be a non-empty object';
        }
        $keys = array_map('strval', array_keys($value));
        $sorted = $keys;
        sort($sorted);
        if ($keys !== $sorted || array_diff($keys, self::LOCALES) !== []) {
            return 'may only have the keys ' . implode(', ', self::LOCALES) . ', sorted';
        }
        foreach ($value as $text) {
            if (!is_string($text) || $text === '' || trim($text) !== $text || mb_strlen($text) > $max) {
                return 'values must be trimmed non-empty strings of at most ' . $max . ' characters';
            }
        }

        return null;
    }
}
