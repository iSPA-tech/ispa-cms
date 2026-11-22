<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use ZipArchive;

/**
 * Builds a deterministic module package zip and its unsigned release manifest (format 1).
 *
 * Refuses modules that cannot be installed at runtime: failed manifest lint, no version, Core / Game* modules, a Vite
 * build config, composer dependencies the root composer.lock does not provide, unsafe composer autoload, symlinks,
 * oversized trees and admin code importing packages the admin shell does not provide. Those refusal checks live in
 * {@see PackageAudit}, the one rule set the hub also runs on extracted packages; build and check call
 * PackageAudit::source() and then only add the build steps (front-end build, sizes of built files, zip, manifest).
 *
 * Shared admin packages: the admin shell's list (base-admin `src/config/shared-packages.json`, format 1) is read
 * from `core.module_packages.shared_admin_packages_file`, else from the workspace or release-layout default. The
 * packages a module imports from it beyond ADMIN_BASELINE_PACKAGES are recorded with their major version in the
 * optional manifest field `admin_packages`; a module that imports none keeps exactly the format 1 fields.
 *
 * Distribution and front-end: a module declaring `"distribution": "deploy"` is refused (DEPLOY_ONLY, with
 * its `distribution_reason`). A module declaring `frontend.entries` is built by FrontendBuilder (shared
 * `vite.module.config.mjs`); the hashed output is added under `<Module>/frontend-build/` before hashing, so every
 * built byte is in the signed `files` map, and the manifest gains the optional field
 * `frontend: {entries, styles, build: "frontend-build"}`. A module shipping its own `frontend-build/` folder in source
 * is refused. A module without declared entries is packaged exactly as before (no Node step, no new field).
 *
 * Edition: a module declaring `"edition": "open"` is refused (EDITION) when it requires a module that is not
 * `open`, because it would ship without that dependency. Modules that declare no edition are not judged here.
 *
 * Determinism: entries are sorted by path, carry a fixed 1980-01-01 00:00:00 modification time and fixed 0644
 * permissions, so two builds of the same tree produce identical zip bytes (the Vite output is content-hashed).
 */
final class PackageBuilder
{
    /** Default php_min when neither composer.json nor module.json states one. */
    public const DEFAULT_PHP_MIN = '8.3';

    /** @see PackageAudit::ADMIN_ALWAYS_ALLOWED_PACKAGES */
    public const ADMIN_ALWAYS_ALLOWED_PACKAGES = PackageAudit::ADMIN_ALWAYS_ALLOWED_PACKAGES;

    /** @see PackageAudit::ADMIN_SHELL_PATH_ALIASES */
    public const ADMIN_SHELL_PATH_ALIASES = PackageAudit::ADMIN_SHELL_PATH_ALIASES;

    /** @see PackageAudit::ADMIN_SHELL_PATH_PREFIXES */
    public const ADMIN_SHELL_PATH_PREFIXES = PackageAudit::ADMIN_SHELL_PATH_PREFIXES;

    /** @see PackageAudit::ADMIN_BASELINE_PACKAGES */
    public const ADMIN_BASELINE_PACKAGES = PackageAudit::ADMIN_BASELINE_PACKAGES;

    /** @see PackageAudit::SHARED_PACKAGES_DEFAULTS */
    public const SHARED_PACKAGES_DEFAULTS = PackageAudit::SHARED_PACKAGES_DEFAULTS;

    /** @see PackageAudit::SCHEMA_GUARDED_MODULES */
    public const SCHEMA_GUARDED_MODULES = PackageAudit::SCHEMA_GUARDED_MODULES;

    /** Longest release-notes section kept for the hub sheet, in bytes. */
    public const CHANGELOG_MAX_BYTES = 20480;

    private readonly FrontendBuilder $frontendBuilder;

    private readonly PackageAudit $audit;

    /**
     * @param FrontendBuilder|null $frontendBuilder builds declared front-end entries (default: pnpm on PATH, base_path())
     * @param PackageAudit|null    $audit           the package rule set (default: a new PackageAudit)
     */
    public function __construct(?FrontendBuilder $frontendBuilder = null, ?PackageAudit $audit = null)
    {
        $this->frontendBuilder = $frontendBuilder ?? new FrontendBuilder();
        $this->audit = $audit ?? new PackageAudit();
    }

    /**
     * Build the zip and manifest for one module.
     *
     * Composer rule: a module composer.json "require" entry other than "php" and "ext-*" is satisfied when the package
     * name appears in composer.lock "packages" or "packages-dev"; version constraints are NOT compared (composer/semver
     * is not installed), so a changed major version of a locked package is not detected.
     *
     * @param string      $module      module folder name, also the package name
     * @param string      $modulesPath root folder that contains the module folders
     * @param string      $outDir      output folder (created when missing)
     * @param string      $keyId       signer key id (16 lowercase hex) recorded in the manifest
     * @param string|null $lockPath    composer.lock to check requirements against (default base_path('composer.lock'))
     *
     * @return array{zip: string, manifest: string, manifest_data: array<string, mixed>}
     *
     * @throws PackageBuildException when the module is refused
     */
    public function build(string $module, string $modulesPath, string $outDir, string $keyId, ?string $lockPath = null): array
    {
        if (preg_match('/^[0-9a-f]{16}$/', $keyId) !== 1) {
            throw new PackageBuildException(PackageBuildException::KEY_ID, 'The key id must be 16 lowercase hex characters.');
        }
        ['root' => $root, 'dir' => $dir, 'manifest' => $manifest, 'version' => $version, 'paths' => $paths, 'composer' => $composer, 'limits' => $limits, 'admin_packages' => $adminPackages, 'frontend' => $frontend]
            = $this->audit->source($module, $modulesPath, $lockPath);

        $built = [];
        if ($frontend !== null) {
            foreach ($this->frontendBuilder->build($module, $dir, $frontend['entries']) as $relative => $bytes) {
                $built[FrontendBuilder::BUILD_DIR . '/' . $relative] = $bytes;
            }
            if (count($paths) + count($built) > (int) $limits['max_files']) {
                throw new PackageBuildException(PackageBuildException::LIMITS, 'Too many files: ' . (count($paths) + count($built)) . ' (limit ' . $limits['max_files'] . ').');
            }
        }

        $files = [];
        $contents = [];
        $unpacked = 0;
        foreach ([...$paths, ...array_keys($built)] as $relative) {
            $relative = (string) $relative;
            $size = isset($built[$relative]) ? strlen($built[$relative]) : (int) filesize($dir . '/' . $relative);
            if ($size > (int) $limits['max_file_bytes']) {
                throw new PackageBuildException(PackageBuildException::LIMITS, 'File too large: ' . $relative . ' (' . $size . ' bytes).');
            }
            $unpacked += $size;
            if ($unpacked > (int) $limits['max_unpacked_bytes']) {
                throw new PackageBuildException(PackageBuildException::LIMITS, 'Unpacked size exceeds ' . $limits['max_unpacked_bytes'] . ' bytes.');
            }
            $data = $built[$relative] ?? file_get_contents($dir . '/' . $relative);
            if ($data === false) {
                throw new PackageBuildException(PackageBuildException::IO, 'Cannot read ' . $relative);
            }
            $entry = $module . '/' . $relative;
            $contents[$entry] = $data;
            $files[$entry] = hash('sha256', $data);
        }
        ksort($files, SORT_STRING);

        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new PackageBuildException(PackageBuildException::IO, 'Cannot create output folder: ' . $outDir);
        }
        $zipPath = rtrim($outDir, '/\\') . '/' . $module . '-' . $version . '.zip';
        $this->writeZip($zipPath, $contents);
        $zipSize = (int) filesize($zipPath);
        if ($zipSize > (int) $limits['max_zip_bytes']) {
            @unlink($zipPath);
            throw new PackageBuildException(PackageBuildException::LIMITS, 'Zip is ' . $zipSize . ' bytes; the limit is ' . $limits['max_zip_bytes'] . '.');
        }

        $adminModule = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];
        $data = [
            'format' => 1,
            'name' => $module,
            'version' => $version,
            'zip_sha256' => (string) hash_file('sha256', $zipPath),
            'zip_size' => $zipSize,
            'unpacked_size' => $unpacked,
            'files' => $files,
            'requires' => $this->requires($manifest['requires'] ?? [], $root),
            'irreversible' => $this->irreversible($manifest['migrations']['irreversible'] ?? []),
            'custom_code' => is_bool($adminModule['custom_code'] ?? null) ? $adminModule['custom_code'] : is_file($dir . '/admin/module.js'),
            'php_min' => $this->phpMin($composer),
            'built_at' => date('c'),
            'key_id' => $keyId,
        ];
        if ($adminPackages !== []) {
            $data['admin_packages'] = $adminPackages;
        }
        if ($frontend !== null) {
            $data['frontend'] = ['entries' => $frontend['entries'], 'styles' => $frontend['styles'], 'build' => FrontendBuilder::BUILD_DIR];
        }
        $manifestPath = rtrim($outDir, '/\\') . '/' . $module . '-' . $version . '.manifest.json';
        $bytes = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($manifestPath, $bytes) === false) {
            throw new PackageBuildException(PackageBuildException::IO, 'Cannot write ' . $manifestPath);
        }

        return ['zip' => $zipPath, 'manifest' => $manifestPath, 'manifest_data' => $data];
    }

    /**
     * Run every refusal check of build() without writing anything (no key, no zip, no manifest).
     *
     * Shares its logic with build(), so a module that passes check() is refused by build() only for the key id,
     * oversized files at read time or an unwritable output folder.
     *
     * @param string      $module      module folder name
     * @param string      $modulesPath root folder that contains the module folders
     * @param string|null $lockPath    composer.lock to check requirements against (default base_path('composer.lock'))
     *
     * @throws PackageBuildException when the module is refused
     */
    public function check(string $module, string $modulesPath, ?string $lockPath = null): void
    {
        $this->audit->source($module, $modulesPath, $lockPath);
    }

    /**
     * Release notes of one version: the body of the `## <version>` section of a CHANGELOG.md.
     *
     * The heading is `## 2026.11.1`, `## 2026.11.1 - 2026-11-14` or `## [2026.11.1] - ...`; only that exact version
     * matches (never a longer one such as 2026.11.10). The section runs to the next `## ` heading. The text is returned
     * as it is (markdown, HTML kept as text for escaping later), trimmed, cut to CHANGELOG_MAX_BYTES on a character
     * boundary; null when the version has no section or an empty one.
     */
    public static function changelogSection(string $markdown, string $version): ?string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $heading = '/^##[ \t]+v?\[?' . preg_quote($version, '/') . '\]?(?![\w.])[^\n]*$/mu';
        if (preg_match($heading, $markdown, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $rest = substr($markdown, $m[0][1] + strlen($m[0][0]));
        $next = preg_match('/^##[ \t]/m', $rest, $n, PREG_OFFSET_CAPTURE) === 1 ? substr($rest, 0, $n[0][1]) : $rest;
        $section = trim($next);
        if ($section === '') {
            return null;
        }
        if (strlen($section) > self::CHANGELOG_MAX_BYTES) {
            $section = rtrim(mb_strcut($section, 0, self::CHANGELOG_MAX_BYTES, 'UTF-8'));
        }

        return $section;
    }

    /**
     * Warnings of the last build or check run, one plain sentence each; empty when there were none.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->audit->warnings();
    }

    /**
     * The manifest `irreversible` value: a boolean by default, or the list of migration names when the setting
     * core.module_packages.publish_irreversible_list is on and the module lists some.
     *
     * The list is de-duplicated (order kept) and must satisfy the ReleaseManifest reader (valid names, at most
     * MAX_IRREVERSIBLE_MIGRATIONS); otherwise the build fails instead of publishing a manifest that sites reject.
     *
     * @return bool|list<string>
     *
     * @throws PackageBuildException LIMITS when the list cannot be published
     */
    private function irreversible(mixed $declared): bool|array
    {
        if (empty($declared)) {
            return false;
        }
        if (!(bool) config('core.module_packages.publish_irreversible_list', false)) {
            return true;
        }
        $names = [];
        foreach (is_array($declared) ? $declared : [] as $name) {
            if (!is_string($name) || preg_match(ReleaseManifest::MIGRATION_NAME_PATTERN, $name) !== 1 || $name === '.' || $name === '..') {
                throw new PackageBuildException(PackageBuildException::LIMITS, 'migrations.irreversible holds an entry that is not a valid migration name.');
            }
            $names[$name] = $name;
        }
        if (count($names) > ReleaseManifest::MAX_IRREVERSIBLE_MIGRATIONS) {
            throw new PackageBuildException(PackageBuildException::LIMITS, 'migrations.irreversible lists ' . count($names) . ' migrations; a manifest may list at most ' . ReleaseManifest::MAX_IRREVERSIBLE_MIGRATIONS . '.');
        }

        return array_values($names);
    }

    /**
     * Normalise module.json "requires" to an object of module name => constraint ("*" for list entries).
     *
     * @return array<string, string>
     */
    private function requires(mixed $requires, string $root): array
    {
        $out = [];
        foreach (is_array($requires) ? $requires : [] as $key => $value) {
            $name = is_int($key) ? (string) $value : $key;
            $name = PackageAudit::moduleFolder($name, $root) ?? ucfirst($name);
            $out[$name] = is_int($key) || !is_string($value) || $value === '' ? '*' : $value;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Minimum PHP version: first version number in composer.json require.php, else the default.
     *
     * @param array<string, mixed> $composer
     */
    private function phpMin(array $composer): string
    {
        $constraint = $composer['require']['php'] ?? null;
        if (is_string($constraint) && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $m) === 1) {
            return $m[1];
        }

        return self::DEFAULT_PHP_MIN;
    }

    /**
     * Write the deterministic zip: sorted entries, fixed mtime, fixed permissions, deflate.
     *
     * @param array<string, string> $contents entry name => bytes
     */
    private function writeZip(string $zipPath, array $contents): void
    {
        @unlink($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PackageBuildException(PackageBuildException::IO, 'Cannot create ' . $zipPath);
        }
        ksort($contents, SORT_STRING);
        $mtime = mktime(0, 0, 0, 1, 1, 1980);
        foreach ($contents as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
            $zip->setMtimeName($name, $mtime);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
        if (!$zip->close()) {
            throw new PackageBuildException(PackageBuildException::IO, 'Cannot finish ' . $zipPath);
        }
    }
}
