<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use App\Support\Modules\RuntimeModules;
use Throwable;

/**
 * Published front-end assets of runtime-installed modules: `<root>/<Name>/<version>/`, web-served as
 * `/runtime-assets/<Name>/<version>/`.
 *
 * The root is `public/runtime-assets`. A deployed release links it to the shared `<base>/runtime/public-assets`
 * folder, so {@see self::path} hands out the resolved folder; on a site that is not a deployed release it
 * is a plain folder, created on the first publish. A deployed release without the link is refused: a plain folder
 * there would disappear with the next deploy.
 *
 * Only regular files whose extension is in {@see RuntimeModules::ASSET_EXTENSIONS} (plus the top-level Vite
 * `manifest.json`) are copied, except SVG files (F5): a static SVG would be served by the web server
 * without `nosniff` or a Content Security Policy, and a script inside it would run when the file is opened directly.
 * They stay in the installed module's `frontend-build` folder and
 * {@see \App\Http\Controllers\RuntimeModuleAssetController} serves them under the same URL with those headers.
 * Folders get mode 0755 and files 0644 so the web server user can read what the queue worker published. A publish
 * copies into a hidden temp folder next to the target and renames it into place, so readers see either nothing or the
 * complete version.
 */
final class RuntimeAssets
{
    /** Folder under `public/` (and URL prefix) of the published assets. */
    public const PUBLIC_FOLDER = 'runtime-assets';

    /** Vite manifest file name at the top of a build. */
    public const MANIFEST = 'manifest.json';

    /** Extension never published as a static file; the application serves it with safe headers. */
    public const APP_SERVED_EXTENSION = 'svg';

    /** Error code of every publish failure. */
    public const PUBLISH_FAILED = 'MODULE_ASSETS_PUBLISH_FAILED';

    /** Test override for the root folder. */
    private static ?string $pathOverride = null;

    /**
     * Absolute, resolved root folder when it exists, otherwise null (also for a dangling link).
     *
     * @param string|null $publicPath public folder; defaults to public_path()
     */
    public static function path(?string $publicPath = null): ?string
    {
        $path = self::$pathOverride ?? (($publicPath ?? public_path()) . DIRECTORY_SEPARATOR . self::PUBLIC_FOLDER);
        if (@lstat($path) === false || !is_dir($path)) {
            return null;
        }
        $real = realpath($path);

        return $real === false ? null : $real;
    }

    /**
     * Point path() at another folder (tests only).
     */
    public static function setPath(?string $path): void
    {
        self::$pathOverride = $path;
    }

    /**
     * Drop the test override set by setPath().
     */
    public static function resetPath(): void
    {
        self::$pathOverride = null;
    }

    /**
     * Build directory of a published version relative to `public/` (what `Vite::useBuildDirectory()` takes).
     */
    public static function buildDirectory(string $name, string $version): string
    {
        return self::PUBLIC_FOLDER . '/' . $name . '/' . $version;
    }

    /**
     * Absolute URL of a published version's folder.
     */
    public static function url(string $name, string $version): string
    {
        return asset(self::buildDirectory($name, $version));
    }

    /**
     * Published versions of a module, sorted; empty when none.
     *
     * @return list<string>
     */
    public static function versions(string $name): array
    {
        $root = self::path();
        if ($root === null || preg_match(ReleaseManifest::NAME_PATTERN, $name) !== 1) {
            return [];
        }
        $moduleDir = $root . '/' . $name;
        if (is_link($moduleDir) || !is_dir($moduleDir)) {
            return [];
        }
        $versions = [];
        foreach (@scandir($moduleDir) ?: [] as $entry) {
            if (preg_match(ReleaseManifest::VERSION_PATTERN, $entry) === 1 && !is_link($moduleDir . '/' . $entry) && is_dir($moduleDir . '/' . $entry)) {
                $versions[] = $entry;
            }
        }
        sort($versions, SORT_STRING);

        return $versions;
    }

    /**
     * Copy a module's front-end build folder (SVG files aside) to `<root>/<Name>/<version>/` and return the number of
     * files published.
     *
     * Callers publish a version that is not live: an install publishes after its placement succeeded (so no module of
     * that name was installed), an upgrade after its version checks (so the version differs from the installed one).
     * A folder already at the target is therefore a leftover of an interrupted run and is replaced.
     *
     * @throws PackageVerificationException MODULE_ASSETS_PUBLISH_FAILED; nothing is left behind
     */
    public static function publish(string $name, string $version, string $sourceDir): int
    {
        if (preg_match(ReleaseManifest::NAME_PATTERN, $name) !== 1 || preg_match(ReleaseManifest::VERSION_PATTERN, $version) !== 1) {
            throw self::fail('module name or version is not valid');
        }
        if (is_link($sourceDir) || !is_dir($sourceDir)) {
            throw self::fail('the module front-end build folder is missing');
        }

        $root = self::root();
        $moduleDir = $root . '/' . $name;
        $createdModuleDir = false;
        if (@lstat($moduleDir) === false) {
            if (!@mkdir($moduleDir, 0755) || !@chmod($moduleDir, 0755)) {
                @rmdir($moduleDir);
                throw self::fail('cannot create the module asset folder');
            }
            $createdModuleDir = true;
        } elseif (is_link($moduleDir) || !is_dir($moduleDir)) {
            throw self::fail('the module asset path is not a folder');
        }

        $target = $moduleDir . '/' . $version;
        $temp = $moduleDir . '/.' . $version . '.publishing-' . bin2hex(random_bytes(6));
        try {
            if (!@mkdir($temp, 0755) || !@chmod($temp, 0755)) {
                throw self::fail('cannot create the temporary asset folder');
            }
            $count = self::copyTree($sourceDir, $temp, '');
            if (@lstat($target) !== false && (is_link($target) || !SafeExtractor::removeTree($target, $moduleDir))) {
                throw self::fail('cannot replace a leftover asset folder');
            }
            if (!@rename($temp, $target)) {
                throw self::fail('cannot move the published assets into place');
            }

            return $count;
        } catch (Throwable $e) {
            SafeExtractor::removeTree($temp, $moduleDir);
            if ($createdModuleDir) {
                @rmdir($moduleDir);
            }
            throw $e instanceof PackageVerificationException ? $e : self::fail('publishing failed unexpectedly');
        }
    }

    /**
     * Remove one published version of a module, or every version (the module folder) when $version is null.
     * The module folder is removed too once it holds nothing. Links are unlinked, never followed.
     *
     * @return bool true when nothing of it is left (also when nothing was published); false on a refused or failed removal
     */
    public static function remove(string $name, ?string $version = null): bool
    {
        if (preg_match(ReleaseManifest::NAME_PATTERN, $name) !== 1
            || ($version !== null && preg_match(ReleaseManifest::VERSION_PATTERN, $version) !== 1)) {
            return false;
        }
        $root = self::path();
        if ($root === null) {
            return true;
        }
        $moduleDir = $root . '/' . $name;
        if (@lstat($moduleDir) === false) {
            return true;
        }
        if ($version === null || is_link($moduleDir) || !is_dir($moduleDir)) {
            return SafeExtractor::removeTree($moduleDir, $root);
        }

        $removed = SafeExtractor::removeTree($moduleDir . '/' . $version, $moduleDir);
        $left = @scandir($moduleDir);
        if ($left !== false && array_diff($left, ['.', '..']) === []) {
            @rmdir($moduleDir);
        }

        return $removed;
    }

    /**
     * The root folder for a publish: the existing one, or a new plain folder on a site that is not a deployed release.
     *
     * @throws PackageVerificationException MODULE_ASSETS_PUBLISH_FAILED
     */
    private static function root(): string
    {
        $root = self::path();
        if ($root !== null) {
            return $root;
        }

        $path = self::$pathOverride ?? public_path(self::PUBLIC_FOLDER);
        if (@lstat($path) !== false) {
            throw self::fail('the public assets folder is a broken link or not a folder');
        }
        if (self::$pathOverride === null && self::isDeployedRelease()) {
            throw self::fail('this release has no public/runtime-assets link to the shared runtime folder');
        }
        if (!@mkdir($path, 0755) || !@chmod($path, 0755)) {
            @rmdir($path);
            throw self::fail('cannot create the public assets folder');
        }
        $real = realpath($path);
        if ($real === false) {
            throw self::fail('cannot resolve the public assets folder');
        }

        return $real;
    }

    /**
     * True when the application runs from `<site>/releases/<release>` (a deployed release).
     */
    private static function isDeployedRelease(): bool
    {
        $base = realpath(base_path());

        return $base !== false && basename(dirname($base)) === 'releases';
    }

    /**
     * Copy the regular files of $from into $to (folders 0755, files 0644) and return the file count.
     * Links, special files and files outside the allowlist are refused; SVG files pass the check but are not copied.
     *
     * @throws PackageVerificationException MODULE_ASSETS_PUBLISH_FAILED
     */
    private static function copyTree(string $from, string $to, string $prefix): int
    {
        $names = array_values(array_diff(@scandir($from) ?: [], ['.', '..']));
        sort($names, SORT_STRING);
        $count = 0;
        foreach ($names as $entry) {
            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (is_link($source)) {
                throw self::fail('the front-end build holds a link');
            }
            if (is_dir($source)) {
                if (!@mkdir($target, 0755) || !@chmod($target, 0755)) {
                    throw self::fail('cannot create an asset folder');
                }
                $count += self::copyTree($source, $target, $relative);
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!is_file($source) || ($relative !== self::MANIFEST && !in_array($extension, RuntimeModules::ASSET_EXTENSIONS, true))) {
                throw self::fail('the front-end build holds a file type that is not published');
            }
            if ($extension === self::APP_SERVED_EXTENSION) {
                continue;
            }
            if (!@copy($source, $target) || !@chmod($target, 0644)) {
                throw self::fail('cannot copy an asset file');
            }
            $count++;
        }

        return $count;
    }

    /**
     * Publish failure with the shared code.
     */
    private static function fail(string $message): PackageVerificationException
    {
        return new PackageVerificationException(self::PUBLISH_FAILED, $message);
    }
}
