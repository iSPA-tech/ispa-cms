<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\Destinations;

use Carbon\CarbonImmutable;
use Cms\Core\Models\Backup\Backup;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds and de-duplicates object keys. Destinations may refuse overwrites, so a key is never reused.
 */
class ObjectKey
{
    private const EXTENSIONS = ['sql.gz', 'sqlite.gz', 'pgdump', 'txt'];

    /**
     * Default prefix: `<label>/app`, or `app` when no server label is configured.
     */
    public static function defaultPrefix(): string
    {
        $label = self::segment((string) config('core.backup.server_label'));

        return $label !== '' ? $label . '/app' : 'app';
    }

    /**
     * Build `<prefix>/<site>/<YYYY-MM-DD>/<db>-<HHMMSS>-<reason>.<ext>` (UTC).
     *
     * @param string|null $prefix Custom prefix with optional {label} and {site} tokens
     *
     * @throws InvalidArgumentException BACKUP_KEY_INVALID for unsafe prefixes
     */
    public static function build(string $db, string $reason, string $extension = 'sql.gz', ?string $prefix = null): string
    {
        $now = CarbonImmutable::instance(now())->utc();
        $name = self::segment($db) . '-' . $now->format('His') . '-' . self::segment($reason) . '.' . ltrim($extension, '.');

        return self::resolvePrefix($prefix) . '/' . Backup::siteSlug() . '/' . $now->format('Y-m-d') . '/' . $name;
    }

    /**
     * Key for a destination probe file: `<prefix>/<site>/_probe/<uuid>.txt`.
     *
     * @throws InvalidArgumentException BACKUP_KEY_INVALID for unsafe prefixes
     */
    public static function probe(?string $prefix = null, ?string $site = null): string
    {
        return self::resolvePrefix($prefix) . '/' . ($site ?? Backup::siteSlug()) . '/_probe/' . Str::uuid()->toString() . '.txt';
    }

    /**
     * Expand tokens and validate a prefix (null/empty = default prefix).
     *
     * @throws InvalidArgumentException BACKUP_KEY_INVALID
     */
    public static function resolvePrefix(?string $prefix): string
    {
        if ($prefix === null || $prefix === '') {
            return self::defaultPrefix();
        }

        $expanded = preg_replace_callback('/\{([^{}]*)\}/', static function (array $m): string {
            return match ($m[1]) {
                'label' => self::segment((string) config('core.backup.server_label')),
                'site' => Backup::siteSlug(),
                default => throw new InvalidArgumentException('BACKUP_KEY_INVALID'),
            };
        }, $prefix) ?? '';

        if ($expanded === '' || str_starts_with($expanded, '/') || preg_match('/[{}\\\\\x00-\x1f]/', $expanded)) {
            throw new InvalidArgumentException('BACKUP_KEY_INVALID');
        }
        foreach (explode('/', $expanded) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('BACKUP_KEY_INVALID');
            }
        }

        return $expanded;
    }

    /**
     * Return a key that does not exist on the disk: the key itself, else with `-HHMMSS` (then `-HHMMSS-2`, ...)
     * inserted before the extension.
     */
    public static function fresh(Filesystem $disk, string $key): string
    {
        if (!$disk->exists($key)) {
            return $key;
        }

        [$base, $ext] = self::splitExtension($key);
        $stamp = '-' . CarbonImmutable::instance(now())->utc()->format('His');

        $candidate = $base . $stamp . $ext;
        for ($n = 2; $disk->exists($candidate); $n++) {
            $candidate = $base . $stamp . '-' . $n . $ext;
        }

        return $candidate;
    }

    /**
     * Split a key into base and extension (known multi-part extensions first).
     *
     * @return array{0: string, 1: string}
     */
    private static function splitExtension(string $key): array
    {
        foreach (self::EXTENSIONS as $ext) {
            if (str_ends_with($key, '.' . $ext)) {
                return [substr($key, 0, -strlen($ext) - 1), '.' . $ext];
            }
        }

        $dot = strrpos($key, '.');
        $slash = strrpos($key, '/');
        if ($dot === false || ($slash !== false && $dot < $slash)) {
            return [$key, ''];
        }

        return [substr($key, 0, $dot), substr($key, $dot)];
    }

    /**
     * Reduce a value to a safe single path segment.
     */
    private static function segment(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]/', '', $value), '.');
    }
}
