<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Runtime modules folder (`base_path('runtime-modules')`): modules that arrive
 * after the release was built and are therefore not in Composer's class map.
 * When nothing exists at that place (a site that never had the deploy link),
 * `storage/app/runtime-modules` is used instead ({@see self::location()}), so
 * switching module install on never writes into the code tree.
 *
 * Every method takes explicit paths so it can run from bootstrap/app.php,
 * before configuration and `.env` are loaded.
 *
 * Validation rules for a module folder `<Name>`:
 * - module.json exists, is a JSON object and its `name` equals the folder name;
 * - every `providers` entry starts with `Cms\<Name>\`;
 * - every `files` entry is relative, has no `..` segment and exists inside the module;
 * - composer.json exists; its `autoload` holds only `psr-4` with prefixes
 *   `Cms\<Name>\` or deeper, each mapped to relative folders without `..`;
 *   `files`, `classmap`, `psr-0` or any other autoload section is rejected.
 *   `autoload-dev` is ignored: the runtime loader never registers it, so it
 *   cannot load code;
 * - the name is not a folder in the release modules folder (`cms/`), because
 *   nwidart would let the runtime module replace the release module; except a
 *   module listed in runtime_modules.json, whose release copy stays as the
 * fallback ({@see RuntimePrecedence},);
 * - the name is not Core or a Game* name ({@see self::isForbiddenName()}), even
 * when the release ships no module of that name (F1);
 * - when the caller passes the signed front-end declaration (the installer
 *   does; the boot-time autoloader does not), the `frontend-build/` folder
 *   follows {@see self::validateFrontend()}.
 */
final class RuntimeModules
{
    /** Folder name of the runtime modules folder under the base path. */
    public const FOLDER = 'runtime-modules';

    /** Folder of a module's prebuilt front-end assets. */
    public const FRONTEND_BUILD_DIR = 'frontend-build';

    /** Vite manifest at the top of the front-end build. */
    public const FRONTEND_MANIFEST = 'manifest.json';

    /**
     * Extensions a published front-end build may hold besides its manifest: the builder's output allowlist
     * (FrontendBuilder::ALLOWED_EXTENSIONS,). No map, html or php.
     */
    public const ASSET_EXTENSIONS = ['js', 'css', 'woff2', 'woff', 'svg', 'png', 'webp', 'jpg', 'gif'];

    /** Allowed module folder names; dot folders such as `.staging` never match. */
    public const NAME_PATTERN = '/^[A-Z][A-Za-z0-9]{1,63}$/';

    /** Names never installed at runtime: Core (exact, any case) and every name starting with "Game" (any case). */
    public const FORBIDDEN_NAME_PATTERN = '/^(?:core$|game)/i';

    /** Fallback folder under the base path, used when nothing exists at FOLDER. */
    public const STORAGE_FOLDER = 'storage/app/runtime-modules';

    /** Test override for the runtime folder path. */
    private static ?string $pathOverride = null;

    /** Test override for the fallback folder (only used while $pathOverride is set). */
    private static ?string $fallbackOverride = null;

    /**
     * Absolute runtime folder path when it is a directory, otherwise null.
     *
     * @param string|null $basePath Application base path; defaults to base_path().
     */
    public static function path(?string $basePath = null): ?string
    {
        $path = self::location($basePath);
        if (!is_dir($path)) {
            return null;
        }

        // Deploys make runtime-modules a symlink to the shared runtime/modules folder. Callers refuse a root that is a
        // link (so a swapped link cannot redirect a placement), so hand them the resolved folder.
        $real = realpath($path);

        return $real === false ? null : $real;
    }

    /**
     * Where the runtime folder (or the deploy's link to it) is expected, whether or not it exists.
     *
     * @param string|null $basePath Application base path; defaults to base_path().
     */
    public static function expectedPath(?string $basePath = null): string
    {
        return self::$pathOverride ?? (($basePath ?? base_path()) . DIRECTORY_SEPARATOR . self::FOLDER);
    }

    /**
     * The runtime folder location every reader uses (autoloader, nwidart scan path, installer): the expected place
     * when anything (folder or link, even a dangling one) is there, else the storage fallback when that folder
     * exists, else the expected place. Takes explicit paths so bootstrap/app.php and config/modules.php can call it.
     *
     * @param string|null $basePath Application base path; defaults to base_path().
     */
    public static function location(?string $basePath = null): string
    {
        $expected = self::expectedPath($basePath);
        if (is_link($expected) || file_exists($expected)) {
            return $expected;
        }
        $fallback = self::fallbackPath($basePath);

        return $fallback !== null && is_dir($fallback) ? $fallback : $expected;
    }

    /**
     * The storage fallback folder (`storage/app/runtime-modules` under the base path); null while a test override
     * without a fallback is set.
     *
     * @param string|null $basePath Application base path; defaults to base_path().
     */
    public static function fallbackPath(?string $basePath = null): ?string
    {
        if (self::$pathOverride !== null) {
            return self::$fallbackOverride;
        }

        return ($basePath ?? base_path()) . DIRECTORY_SEPARATOR . self::STORAGE_FOLDER;
    }

    /**
     * Point path() at another folder, optionally with a storage fallback folder (tests only).
     */
    public static function setPath(?string $path, ?string $fallback = null): void
    {
        self::$pathOverride = $path;
        self::$fallbackOverride = $path !== null ? $fallback : null;
    }

    /**
     * Drop the test override set by setPath().
     */
    public static function resetPath(): void
    {
        self::$pathOverride = null;
        self::$fallbackOverride = null;
    }

    /**
     * Module folder names in the runtime folder, sorted.
     *
     * @return list<string>
     */
    public static function names(string $runtimeDir): array
    {
        $entries = @scandir($runtimeDir);
        if ($entries === false) {
            return [];
        }

        $names = [];
        foreach ($entries as $entry) {
            if (preg_match(self::NAME_PATTERN, $entry) === 1 && is_dir($runtimeDir . DIRECTORY_SEPARATOR . $entry)) {
                $names[] = $entry;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * Whether $name may never be a runtime module: Core and Game* modules ship only with a release.
     *
     * One rule for the package builder, the site installer (through validate()) and the hub publisher.
     */
    public static function isForbiddenName(string $name): bool
    {
        return preg_match(self::FORBIDDEN_NAME_PATTERN, $name) === 1;
    }

    /**
     * Validate one runtime module folder.
     *
     * @param string $dir Module folder.
     * @param string $name Folder name (expected module name).
     * @param string|null $releaseModulesDir Release modules folder; defaults to base_path('cms').
     * @param list<string>|null $frontendEntries Front-end entries of the signed manifest: `[]` when the package
     *        declares none (then no `frontend-build/` folder may exist); null skips the front-end check (boot).
     * @param bool $releaseFallback True for a module listed in runtime_modules.json that the release also ships
     *: the release copy stays as the fallback, so the release-name rule is skipped.
     * @return list<string> Error messages; empty when the module is valid.
     */
    public static function validate(string $dir, string $name, ?string $releaseModulesDir = null, ?array $frontendEntries = null, bool $releaseFallback = false): array
    {
        $errors = [];
        $namespace = 'Cms\\' . $name . '\\';

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = "invalid module name '{$name}'";
        }
        if (self::isForbiddenName($name)) {
            $errors[] = "module name '{$name}' is reserved: Core and Game* modules are never installed at runtime";
        }

        $releaseModulesDir ??= base_path('cms');
        if (! $releaseFallback && is_dir($releaseModulesDir . DIRECTORY_SEPARATOR . $name)) {
            $errors[] = "name '{$name}' already exists in the release modules folder";
        }

        $module = self::readJson($dir . DIRECTORY_SEPARATOR . 'module.json');
        if ($module === null) {
            $errors[] = 'module.json missing or not a JSON object';
        } else {
            if (($module['name'] ?? null) !== $name) {
                $errors[] = 'module.json name does not match the folder name';
            }
            foreach ((array) ($module['providers'] ?? []) as $provider) {
                if (! is_string($provider) || ! str_starts_with(ltrim($provider, '\\'), $namespace)) {
                    $errors[] = 'provider outside ' . $namespace . ': ' . (is_string($provider) ? $provider : gettype($provider));
                }
            }
            foreach ((array) ($module['files'] ?? []) as $file) {
                if (! is_string($file) || ! self::isSafeRelative($file)) {
                    $errors[] = 'files entry is not a safe relative path: ' . (is_string($file) ? $file : gettype($file));
                } elseif (! self::isInside($dir . DIRECTORY_SEPARATOR . $file, $dir, true)) {
                    $errors[] = 'files entry missing or outside the module: ' . $file;
                }
            }
        }

        if ($frontendEntries !== null) {
            array_push($errors, ...self::validateFrontend($dir, $frontendEntries));
        }

        $composer = self::readJson($dir . DIRECTORY_SEPARATOR . 'composer.json');
        if ($composer === null) {
            $errors[] = 'composer.json missing or not a JSON object';

            return $errors;
        }

        $autoload = $composer['autoload'] ?? [];
        if (! is_array($autoload)) {
            $errors[] = 'composer.json autoload is not an object';

            return $errors;
        }
        foreach (array_keys($autoload) as $section) {
            if ($section !== 'psr-4') {
                $errors[] = "composer.json autoload section '{$section}' is not allowed";
            }
        }
        foreach ((array) ($autoload['psr-4'] ?? []) as $prefix => $paths) {
            $prefix = (string) $prefix;
            if (! str_starts_with($prefix, $namespace) || ! str_ends_with($prefix, '\\')) {
                $errors[] = 'psr-4 prefix outside ' . $namespace . ': ' . $prefix;
            }
            $paths = is_array($paths) ? $paths : [$paths];
            foreach ($paths as $path) {
                if (! is_string($path) || ! self::isSafeRelative($path)) {
                    $errors[] = "psr-4 folder for {$prefix} is not a safe relative path";
                }
            }
        }

        return $errors;
    }

    /**
     * Check a module's `frontend-build/` folder against the signed front-end declaration.
     *
     * With no declared entries the folder must not exist. With entries it must be a real folder holding only regular
     * files: the top-level Vite `manifest.json` and files whose extension is in ASSET_EXTENSIONS. The Vite manifest is
     * a JSON object keyed by relative source paths; every `file`, `css` and `assets` path it names exists in the build;
     * every declared entry is one of its entry chunks.
     *
     * @param list<string> $entries Front-end entries of the signed manifest (`[]` when none).
     * @return list<string> Error messages; empty when the folder is valid.
     */
    public static function validateFrontend(string $dir, array $entries): array
    {
        $build = $dir . DIRECTORY_SEPARATOR . self::FRONTEND_BUILD_DIR;
        $exists = @lstat($build) !== false;
        if ($entries === []) {
            return $exists ? [self::FRONTEND_BUILD_DIR . ' folder present without a signed front-end declaration'] : [];
        }
        if (! $exists || is_link($build) || ! is_dir($build)) {
            return [self::FRONTEND_BUILD_DIR . ' folder is missing'];
        }

        $errors = [];
        $files = [];
        self::walkBuild($build, '', $files, $errors);
        foreach (array_keys($files) as $relative) {
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if ($relative !== self::FRONTEND_MANIFEST && ! in_array($extension, self::ASSET_EXTENSIONS, true)) {
                $errors[] = self::FRONTEND_BUILD_DIR . ' holds a file type that is not allowed: ' . $relative;
            }
        }
        if (! isset($files[self::FRONTEND_MANIFEST])) {
            $errors[] = self::FRONTEND_BUILD_DIR . ' has no ' . self::FRONTEND_MANIFEST;

            return $errors;
        }

        $manifest = json_decode((string) file_get_contents($build . DIRECTORY_SEPARATOR . self::FRONTEND_MANIFEST), true);
        if (! is_array($manifest) || $manifest === [] || array_is_list($manifest)) {
            $errors[] = self::FRONTEND_MANIFEST . ' is not a JSON object';

            return $errors;
        }
        foreach ($manifest as $key => $chunk) {
            $key = (string) $key;
            if ($key === '' || ! self::isSafeRelative($key) || ! is_array($chunk)) {
                $errors[] = self::FRONTEND_MANIFEST . ' has a key that is not a relative source path';

                continue;
            }
            $named = [$chunk['file'] ?? null, ...(array) ($chunk['css'] ?? []), ...(array) ($chunk['assets'] ?? [])];
            foreach ($named as $file) {
                if (! is_string($file) || $file === self::FRONTEND_MANIFEST || ! isset($files[$file])) {
                    $errors[] = self::FRONTEND_MANIFEST . ' names a file the build does not hold: ' . (is_string($file) ? $file : gettype($file));
                }
            }
        }
        foreach ($entries as $entry) {
            if (($manifest[$entry]['isEntry'] ?? false) !== true) {
                $errors[] = self::FRONTEND_MANIFEST . ' has no entry for the declared ' . $entry;
            }
        }

        return $errors;
    }

    /**
     * Collect the regular files under a build folder (relative path => true); links and special files are errors.
     *
     * @param array<string, true> $files
     * @param list<string> $errors
     */
    private static function walkBuild(string $root, string $prefix, array &$files, array &$errors): void
    {
        $names = array_values(array_diff(@scandir($root . ($prefix === '' ? '' : DIRECTORY_SEPARATOR . $prefix)) ?: [], ['.', '..']));
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            $full = $root . DIRECTORY_SEPARATOR . $relative;
            if (is_link($full)) {
                $errors[] = self::FRONTEND_BUILD_DIR . ' holds a link: ' . $relative;
            } elseif (is_dir($full)) {
                self::walkBuild($root, $relative, $files, $errors);
            } elseif (is_file($full)) {
                $files[$relative] = true;
            } else {
                $errors[] = self::FRONTEND_BUILD_DIR . ' holds something that is not a file: ' . $relative;
            }
        }
    }

    /**
     * The psr-4 map of a module whose validate() returned no errors.
     *
     * @return array<string, list<string>> Prefix => relative folders (without trailing slash).
     */
    public static function psr4(string $dir): array
    {
        $composer = self::readJson($dir . DIRECTORY_SEPARATOR . 'composer.json') ?? [];
        $map = [];
        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $map[(string) $prefix][] = trim((string) $path, '/\\');
            }
        }

        return $map;
    }

    /**
     * True when $path is relative, not absolute (Unix or Windows) and has no `..` segment.
     */
    private static function isSafeRelative(string $path): bool
    {
        if ($path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:/', $path) === 1)) {
            return false;
        }

        return ! in_array('..', preg_split('#[/\\\\]#', $path) ?: [], true);
    }

    /**
     * True when $path resolves (symlinks followed) inside $root; optionally it must be a file.
     */
    private static function isInside(string $path, string $root, bool $file): bool
    {
        $real = realpath($path);
        $realRoot = realpath($root);
        if ($real === false || $realRoot === false || ($file && ! is_file($real))) {
            return false;
        }

        return str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * Decode a JSON object file; null when missing or not an object.
     *
     * @return array<string, mixed>|null
     */
    private static function readJson(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
