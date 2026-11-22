<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Integrity report: compares the files of an installed runtime module with the file hashes of the signed
 * package it came from.
 *
 * At install and upgrade the installer keeps the signed `sig.json` of the package beside the runtime folder
 * (`<runtime>/.integrity/<Name>.json`, see {@see self::record()}). A check re-verifies that document with the trusted
 * keys, then hashes every listed file. A file whose hash differs is `modified`, a listed file that is gone is
 * `missing`. Files that are not in the manifest are not reported (generated files may live beside the module).
 *
 * A module with no record (installed before this feature, shipped in the release, signature no longer verifying, record
 * of another version) is `unknown`: no claim is made either way. Nothing here ever blocks an install, an upgrade, a
 * module switch or a request: every failure is read as `unknown`, and the report is information only.
 *
 * @package Cms\Core\Services\Modules\Install
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
final class ModuleIntegrity
{
    public const STATE_OK = 'ok';
    public const STATE_CHANGED = 'changed';
    public const STATE_UNKNOWN = 'unknown';

    public const FILE_MODIFIED = 'modified';
    public const FILE_MISSING = 'missing';

    /** Folder inside the runtime folder that holds one signed record per module. */
    public const FOLDER = '.integrity';

    /** Cache key of the last stored result (the scheduled command and the "check now" button write it). */
    public const CACHE_KEY = 'platform:module-integrity:v1';

    /** The stored result stays readable this long when no new check runs. */
    public const CACHE_DAYS = 3;

    /** Most changed files listed for one module (the count is always complete). */
    public const MAX_LISTED = 200;

    /** Largest record read (sig.json of a large package). */
    private const MAX_RECORD_BYTES = 4_000_000;

    private ?PackageVerifier $verifier;

    private ?string $runtimeRoot;

    /**
     * @param string|null          $runtimeRoot Runtime modules folder; null = {@see RuntimeModules::path()}.
     * @param PackageVerifier|null $verifier    Null = built from the configured trusted keys on first use.
     */
    public function __construct(?string $runtimeRoot = null, ?PackageVerifier $verifier = null)
    {
        $this->runtimeRoot = $runtimeRoot;
        $this->verifier = $verifier;
    }

    /**
     * Keep the signed document of an installed package for later checks. Best effort: a failure is swallowed because
     * the install must never fail on this.
     *
     * @param string $runtimeRoot Runtime modules folder.
     * @param string $name        Module name.
     * @param string $sigJson     The verified package sig.json document.
     * @return bool Whether the record was written.
     */
    public static function record(string $runtimeRoot, string $name, string $sigJson): bool
    {
        try {
            if (preg_match(ReleaseManifest::NAME_PATTERN, $name) !== 1 || strlen($sigJson) > self::MAX_RECORD_BYTES) {
                return false;
            }
            $dir = rtrim($runtimeRoot, '/') . '/' . self::FOLDER;
            if (is_link($dir) || (! is_dir($dir) && ! @mkdir($dir, 0755) && ! is_dir($dir))) {
                return false;
            }
            $target = $dir . '/' . $name . '.json';
            $temp = $dir . '/.' . $name . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, $sigJson) === false) {
                return false;
            }
            @chmod($temp, 0644);
            if (! @rename($temp, $target)) {
                @unlink($temp);

                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check one installed runtime module.
     *
     * @param string $name Module name.
     * @return array{state: string, version: ?string, changed: int, files: list<array{path: string, status: string}>, truncated: bool}
     */
    public function check(string $name): array
    {
        $unknown = ['state' => self::STATE_UNKNOWN, 'version' => null, 'changed' => 0, 'files' => [], 'truncated' => false];

        try {
            $root = $this->runtimeRoot ?? RuntimeModules::path();
            if ($root === null || preg_match(ReleaseManifest::NAME_PATTERN, $name) !== 1) {
                return $unknown;
            }
            $manifest = $this->manifest($root, $name);
            $moduleDir = $root . '/' . $name;
            if ($manifest === null || $manifest->name !== $name || is_link($moduleDir) === false && ! is_dir($moduleDir)) {
                return $unknown;
            }
            // A link that no longer points at the recorded version means the record belongs to another copy.
            if (is_link($moduleDir) && basename((string) readlink($moduleDir)) !== $name . '-' . $manifest->version) {
                return $unknown;
            }

            $changed = [];
            foreach ($manifest->files as $entry => $hash) {
                $relative = substr($entry, strlen($name) + 1);
                $path = $moduleDir . '/' . $relative;
                if (! is_file($path) && ! is_link($path)) {
                    $changed[] = ['path' => $relative, 'status' => self::FILE_MISSING];
                } elseif (is_link($path) || ! hash_equals($hash, (string) @hash_file('sha256', $path))) {
                    $changed[] = ['path' => $relative, 'status' => self::FILE_MODIFIED];
                }
            }

            return [
                'state' => $changed === [] ? self::STATE_OK : self::STATE_CHANGED,
                'version' => $manifest->version,
                'changed' => count($changed),
                'files' => array_slice($changed, 0, self::MAX_LISTED),
                'truncated' => count($changed) > self::MAX_LISTED,
            ];
        } catch (Throwable) {
            return $unknown;
        }
    }

    /**
     * Check several modules.
     *
     * @param list<string> $names Module names.
     * @return array<string, array{state: string, version: ?string, changed: int, files: list<array{path: string, status: string}>, truncated: bool}>
     */
    public function checkAll(array $names): array
    {
        $results = [];
        foreach ($names as $name) {
            $results[(string) $name] = $this->check((string) $name);
        }
        ksort($results, SORT_STRING);

        return $results;
    }

    /**
     * Store a check result for the admin page and the fleet report.
     *
     * @param array<string, array<string, mixed>> $results Output of {@see self::checkAll()}.
     */
    public static function store(array $results): void
    {
        try {
            Cache::put(self::CACHE_KEY, ['checked_at' => Carbon::now()->toIso8601String(), 'modules' => $results], Carbon::now()->addDays(self::CACHE_DAYS));
        } catch (Throwable) {
            // Information only.
        }
    }

    /**
     * The last stored result, or null when no check ran lately.
     *
     * @return array{checked_at: string, modules: array<string, array<string, mixed>>}|null
     */
    public static function stored(): ?array
    {
        try {
            $value = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) && is_string($value['checked_at'] ?? null) && is_array($value['modules'] ?? null) ? $value : null;
    }

    /**
     * Changed-file counts of the modules the last check could compare (the fleet report carries these and nothing else).
     *
     * @return array<string, int>|null Null when no check ran lately.
     */
    public static function counts(): ?array
    {
        $stored = self::stored();
        if ($stored === null) {
            return null;
        }
        $counts = [];
        foreach ($stored['modules'] as $name => $row) {
            if (is_array($row) && in_array($row['state'] ?? null, [self::STATE_OK, self::STATE_CHANGED], true)) {
                $counts[(string) $name] = max(0, (int) ($row['changed'] ?? 0));
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Names to check: every module with a record, plus the given module names (they come out `unknown` when they have none).
     *
     * @param list<string> $extra Module names known to the site.
     * @return list<string>
     */
    public function names(array $extra = []): array
    {
        $names = array_map('strval', $extra);
        $root = $this->runtimeRoot ?? RuntimeModules::path();
        if ($root !== null) {
            foreach (glob($root . '/' . self::FOLDER . '/*.json') ?: [] as $file) {
                $names[] = basename($file, '.json');
            }
        }
        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => preg_match(ReleaseManifest::NAME_PATTERN, $n) === 1)));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Read and re-verify the record of one module; null when absent, unreadable or not verifying.
     */
    private function manifest(string $root, string $name): ?ReleaseManifest
    {
        $path = $root . '/' . self::FOLDER . '/' . $name . '.json';
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        $json = $size !== false && $size <= self::MAX_RECORD_BYTES ? @file_get_contents($path) : false;
        if (! is_string($json) || $json === '') {
            return null;
        }
        $this->verifier ??= new PackageVerifier();

        return $this->verifier->verify($json);
    }
}
