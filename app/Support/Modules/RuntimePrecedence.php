<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Throwable;

/**
 * Version-aware precedence between the release copy (`cms/<Name>`) and the runtime copy
 * (`runtime-modules/<Name>`) of a module the release ships.
 *
 * Default: the release copy wins (see ReleaseFirstModuleRepository). The runtime copy wins only when ALL of these hold:
 *  1. the module is listed in `<base>/runtime_modules.json` (the project's list of modules installed online; a JSON
 *     list of names or an object `{"Name": true}`, the same format the deploy sync reads; any other shape lists nothing);
 *  2. the release copy has a readable module.json whose `name` is the folder name and whose `version` is numeric;
 *  3. the runtime copy was placed by the installer: `runtime-modules/<Name>` is a link to `.versions/<Name>-<version>`
 *     inside the runtime folder (`.versions` itself not a link), and its module.json `name` and `version` match that
 *     link. This checks the installer's layout, not the package signature: write access to the runtime folder is
 *     trusted as it already is for runtime-only modules;
 *  4. the runtime version is greater than the release version;
 *  5. the runtime module.json declares `requires.core` as a range (object form, not `*`) and the release Core version
 *     (`cms/Core/module.json`) satisfies it;
 *  6. the runtime folder passes {@see RuntimeModules::validate()} (the release-name rule aside) and every declared
 *     provider class has its file inside the module.
 * Anything unreadable, missing or malformed means release. The release copy is never removed by this rule: a fresh
 * server, a broken or removed runtime copy, or a rollback falls back to it on the next boot.
 *
 * Dependency-free on purpose: bootstrap/app.php calls it before configuration exists, and the deploy scripts call
 * {@see self::cliLine()} through `php -r` with only this file, RuntimeModules.php and RuntimeModuleAutoloader.php
 * required, so the boot path and the deploy path share one rule.
 */
final class RuntimePrecedence
{
    /** Project list of online-installed modules, beside modules_statuses.json at the base path. */
    public const LIST_FILE = 'runtime_modules.json';

    /** Folder of installed versions inside the runtime folder (RuntimePlacement::VERSIONS_FOLDER). */
    public const VERSIONS_FOLDER = '.versions';

    /** Numeric dotted version accepted on both copies and on Core. */
    private const VERSION_PATTERN = '/^\d+(?:\.\d+)*$/';

    /** @var array<string, array{runtime: bool, reason: string, release_version: ?string, runtime_version: ?string}> Decisions per base path and name. */
    private static array $decisions = [];

    /** @var array<string, list<string>> Listed names per base path. */
    private static array $listed = [];

    /**
     * Whether the runtime copy of $name wins over the release copy (memoised per process).
     */
    public static function preferRuntime(string $basePath, string $name): bool
    {
        return self::decide($basePath, $name)['runtime'];
    }

    /**
     * The decision for $name with the reason, memoised per process.
     *
     * @return array{runtime: bool, reason: string, release_version: ?string, runtime_version: ?string}
     */
    public static function decide(string $basePath, string $name): array
    {
        $key = $basePath . "\0" . $name;

        return self::$decisions[$key] ??= self::evaluate($basePath, $name);
    }

    /**
     * Module names listed in `<base>/runtime_modules.json`; empty when the file is absent or malformed.
     *
     * @return list<string>
     */
    public static function listed(string $basePath): array
    {
        return self::$listed[$basePath] ??= self::readList($basePath);
    }

    /**
     * Module names listed in `<base>/runtime_modules.json`, read now (not memoised); empty when the file is absent or
     * malformed. The core upgrade reads the list a downloaded package ships with it.
     *
     * @return list<string>
     */
    public static function readList(string $basePath): array
    {
        $names = [];
        try {
            $file = $basePath . DIRECTORY_SEPARATOR . self::LIST_FILE;
            if (is_file($file)) {
                $payload = json_decode((string) file_get_contents($file), true);
                if (is_array($payload) && array_is_list($payload)) {
                    $names = array_values(array_filter($payload, static fn (mixed $n): bool => is_string($n) && $n !== ''));
                    if (count($names) !== count($payload)) {
                        $names = [];
                    }
                } elseif (is_array($payload)) {
                    $names = array_keys(array_filter($payload, static fn (mixed $v): bool => $v === true));
                    $names = count($names) === count($payload) ? array_map('strval', $names) : [];
                }
            }
        } catch (Throwable) {
            $names = [];
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether $version satisfies a constraint like `>=2026.11.0,<2027.0.0` (same grammar as ModuleVersionConstraint).
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (explode(',', $constraint) as $part) {
            $part = trim($part);
            if ($part === '*') {
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|=)?\s*(\d+(?:\.\d+)*)$/', $part, $m) !== 1) {
                return false;
            }
            $operator = $m[1] === '' || $m[1] === '=' ? '==' : $m[1];
            if (! version_compare($version, $m[2], $operator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The `requires.core` range of the runtime copy of $name (`runtime-modules/<Name>/module.json`), or null when the
     * copy is missing or declares no range (rule 5). Used by the core upgrade to test the copy against a new Core.
     */
    public static function runtimeCoreRange(string $basePath, string $name): ?string
    {
        try {
            $dir = realpath(RuntimeModules::location($basePath) . DIRECTORY_SEPARATOR . $name);

            return $dir === false ? null : self::coreRange(self::manifest($dir)['requires'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One line for the deploy scripts: `runtime <reason>` or `release <reason>`.
     */
    public static function cliLine(string $basePath, string $name): string
    {
        $decision = self::decide($basePath, $name);

        return ($decision['runtime'] ? 'runtime ' : 'release ') . $decision['reason'];
    }

    /**
     * Forget the memoised decisions (tests, and the installer after it changed the runtime folder).
     */
    public static function forget(): void
    {
        self::$decisions = [];
        self::$listed = [];
    }

    /**
     * Evaluate the rule; any exception means release.
     *
     * @return array{runtime: bool, reason: string, release_version: ?string, runtime_version: ?string}
     */
    private static function evaluate(string $basePath, string $name): array
    {
        $result = static fn (bool $runtime, string $reason, ?string $release = null, ?string $runtimeVersion = null): array => [
            'runtime' => $runtime,
            'reason' => $reason,
            'release_version' => $release,
            'runtime_version' => $runtimeVersion,
        ];

        try {
            if (! in_array($name, self::listed($basePath), true)) {
                return $result(false, 'not listed in ' . self::LIST_FILE);
            }
            if (preg_match(RuntimeModules::NAME_PATTERN, $name) !== 1 || RuntimeModules::isForbiddenName($name)) {
                return $result(false, 'not a runtime module name');
            }

            $releaseModules = $basePath . DIRECTORY_SEPARATOR . 'cms';
            $release = self::manifest($releaseModules . DIRECTORY_SEPARATOR . $name);
            if ($release === null || ($release['name'] ?? null) !== $name || ! self::isVersion($release['version'] ?? null)) {
                return $result(false, 'release copy unreadable');
            }
            $releaseVersion = (string) $release['version'];

            $runtimeRoot = realpath(RuntimeModules::location($basePath));
            if ($runtimeRoot === false || ! is_dir($runtimeRoot)) {
                return $result(false, 'no runtime folder', $releaseVersion);
            }
            $link = $runtimeRoot . DIRECTORY_SEPARATOR . $name;
            if (! is_link($link)) {
                return $result(false, 'runtime copy not placed by the installer', $releaseVersion);
            }
            $target = (string) readlink($link);
            if (preg_match('#^' . preg_quote(self::VERSIONS_FOLDER, '#') . '/' . preg_quote($name, '#') . '-(\d+(?:\.\d+)*)$#', $target, $m) !== 1) {
                return $result(false, 'runtime copy not placed by the installer', $releaseVersion);
            }
            $linkVersion = $m[1];
            if (is_link($runtimeRoot . DIRECTORY_SEPARATOR . self::VERSIONS_FOLDER)) {
                return $result(false, 'versions folder is a link', $releaseVersion);
            }
            $dir = realpath($link);
            $versions = realpath($runtimeRoot . DIRECTORY_SEPARATOR . self::VERSIONS_FOLDER);
            if ($dir === false || $versions === false || ! is_dir($dir) || dirname($dir) !== $versions) {
                return $result(false, 'runtime copy missing', $releaseVersion);
            }

            $runtime = self::manifest($dir);
            if ($runtime === null || ($runtime['name'] ?? null) !== $name || ! self::isVersion($runtime['version'] ?? null)) {
                return $result(false, 'runtime copy unreadable', $releaseVersion);
            }
            $runtimeVersion = (string) $runtime['version'];
            if ($runtimeVersion !== $linkVersion) {
                return $result(false, 'runtime version does not match its installed folder', $releaseVersion, $runtimeVersion);
            }
            if (version_compare($runtimeVersion, $releaseVersion) <= 0) {
                return $result(false, 'runtime copy is not newer than the release copy', $releaseVersion, $runtimeVersion);
            }

            $coreRange = self::coreRange($runtime['requires'] ?? null);
            if ($coreRange === null) {
                return $result(false, 'runtime copy declares no Core range', $releaseVersion, $runtimeVersion);
            }
            $core = self::manifest($releaseModules . DIRECTORY_SEPARATOR . 'Core');
            if ($core === null || ! self::isVersion($core['version'] ?? null)) {
                return $result(false, 'release Core version unreadable', $releaseVersion, $runtimeVersion);
            }
            if (! self::satisfies((string) $core['version'], $coreRange)) {
                return $result(false, 'runtime copy does not accept release Core ' . $core['version'], $releaseVersion, $runtimeVersion);
            }

            if (RuntimeModules::validate($link, $name, $releaseModules, null, true) !== []) {
                return $result(false, 'runtime copy is invalid', $releaseVersion, $runtimeVersion);
            }
            if (RuntimeModuleAutoloader::brokenProviders($dir, $link, RuntimeModules::psr4($link)) !== null) {
                return $result(false, 'runtime copy provider missing', $releaseVersion, $runtimeVersion);
            }

            return $result(true, "runtime {$runtimeVersion} is newer than release {$releaseVersion} and accepts Core " . $core['version'], $releaseVersion, $runtimeVersion);
        } catch (Throwable) {
            return $result(false, 'precedence check failed');
        }
    }

    /**
     * The Core range of a `requires` value: object form with a `core` key (any case), not empty or `*`.
     */
    private static function coreRange(mixed $requires): ?string
    {
        if (! is_array($requires) || array_is_list($requires)) {
            return null;
        }
        foreach ($requires as $module => $constraint) {
            if (strtolower((string) $module) === 'core' && is_string($constraint)) {
                $constraint = trim($constraint);

                return $constraint === '' || $constraint === '*' ? null : $constraint;
            }
        }

        return null;
    }

    /**
     * module.json of a module folder as an object, or null.
     *
     * @return array<string, mixed>|null
     */
    private static function manifest(string $dir): ?array
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'module.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    /**
     * True for a numeric dotted version string.
     */
    private static function isVersion(mixed $version): bool
    {
        return is_string($version) && preg_match(self::VERSION_PATTERN, $version) === 1;
    }
}
