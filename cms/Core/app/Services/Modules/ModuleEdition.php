<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules;

/**
 * Distribution buckets ("editions") of the modules.
 *
 * `module.json` carries `"edition": "open|premium|internal|client"`. A module without a valid value counts as
 * `internal`, so nothing leaks by default. Game* modules are the owner's own: they declare `client` (owner
 * 2026-11-18); one without a declaration is `game` by name, and a Game* module can never be `open`, whatever it
 * declares. This is a different key from the module `distribution` (online|deploy), which only says whether a module
 * installs at runtime.
 *
 * Rule: an `open` module may require only `open` modules (it ships in the open-source export, so every dependency must
 * ship with it). Other buckets may require anything.
 */
final class ModuleEdition
{
    public const OPEN = 'open';
    public const PREMIUM = 'premium';
    public const INTERNAL = 'internal';
    public const CLIENT = 'client';
    public const GAME = 'game';

    /** Values a module.json may declare (`game` is derived from the name, never written). */
    public const DECLARABLE = [self::OPEN, self::PREMIUM, self::INTERNAL, self::CLIENT];

    /** Every bucket, in report order. */
    public const ALL = [self::OPEN, self::PREMIUM, self::INTERNAL, self::CLIENT, self::GAME];

    /**
     * Bucket of one module: the declared value when valid, else `internal`. Game* names never become `open`: their
     * declared non-open value counts, otherwise they are `game`.
     *
     * @param array<string, mixed>|null $manifest decoded module.json, or null when unreadable
     */
    public static function of(string $module, ?array $manifest): string
    {
        $declared = $manifest['edition'] ?? null;
        $valid = is_string($declared) && in_array($declared, self::DECLARABLE, true);
        if (str_starts_with($module, 'Game')) {
            return $valid && $declared !== self::OPEN ? $declared : self::GAME;
        }

        return $valid ? $declared : self::INTERNAL;
    }

    /**
     * Whether module.json declares a valid edition itself (false means the `internal` fallback applies).
     *
     * @param array<string, mixed>|null $manifest
     */
    public static function isDeclared(?array $manifest): bool
    {
        $declared = $manifest['edition'] ?? null;

        return is_string($declared) && in_array($declared, self::DECLARABLE, true);
    }

    /**
     * Bucket of a module folder under $modulesPath, or null when the folder or its module.json is not there.
     */
    public static function ofFolder(string $name, string $modulesPath): ?string
    {
        $file = self::folderFile($name, $modulesPath);
        if ($file === null) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($file), true);

        return self::of(basename(dirname($file)), is_array($manifest) ? $manifest : null);
    }

    /**
     * Sentences naming each required module that an `open` module may not depend on; empty for other buckets.
     *
     * Required modules whose folder is not found under $modulesPath are not judged (the manifest lint and the
     * installer report missing requirements).
     *
     * @param array<string, mixed> $manifest   decoded module.json of $module
     * @param list<string>         $requireNames names of the required modules (any case)
     *
     * @return list<string>
     */
    public static function dependencyViolations(string $module, array $manifest, array $requireNames, string $modulesPath): array
    {
        if (self::of($module, $manifest) !== self::OPEN) {
            return [];
        }
        $problems = [];
        foreach ($requireNames as $required) {
            $bucket = self::ofFolder($required, $modulesPath);
            if ($bucket !== null && $bucket !== self::OPEN) {
                $problems[] = 'Open module ' . $module . ' requires ' . $required . ', which is ' . $bucket . '.';
            }
        }

        return $problems;
    }

    /**
     * Names a manifest `requires` field lists (list of names or name => constraint map).
     *
     * @return list<string>
     */
    public static function requireNames(mixed $requires): array
    {
        $out = [];
        foreach (is_array($requires) ? $requires : [] as $key => $value) {
            $name = is_int($key) ? $value : $key;
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * module.json path of a folder, matching the name case-insensitively (manifests say `core`, folders say `Core`).
     */
    private static function folderFile(string $name, string $modulesPath): ?string
    {
        $root = rtrim($modulesPath, '/\\');
        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return null;
        }
        $exact = $root . '/' . $name . '/module.json';
        if (is_file($exact)) {
            return $exact;
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (strcasecmp(basename($dir), $name) === 0 && is_file($dir . '/module.json')) {
                return $dir . '/module.json';
            }
        }

        return null;
    }
}
