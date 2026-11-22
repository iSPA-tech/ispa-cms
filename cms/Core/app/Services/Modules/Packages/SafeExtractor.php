<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use Cms\Core\Services\Modules\ModuleEdition;
use Throwable;
use ZipArchive;

/**
 * Unpacks a verified module package zip into an empty staging folder, and nothing else.
 *
 * Every entry is validated before a single byte is written: safe relative path under "<Name>/", regular file or
 * folder only, no case-folded duplicates, size / count / ratio limits, and the exact file set of the signed manifest.
 * Files are streamed into new files (never overwriting), counted against their declared size and hashed on the fly.
 * The declared sizes must add up to the manifest "unpacked_size" (checked before any byte is written) and so must the
 * bytes actually written. A stream that runs past its declared size is cut off at once, never written beyond it.
 * On any failure everything the extractor wrote is removed again, so the staging folder is left empty.
 *
 * Buyer mark: a premium hub download is a per-grant copy of the signed package with `<Name>/LICENSEE.json`
 * (required) and `<Name>/LICENSEE.sig` (optional) added. It is accepted only when the caller passes the copy's zip
 * SHA-256 and size from the hub's signed ticket answer ($copy): the zip is then checked against those values instead
 * of the manifest's, the two licensee files (at most LICENSEE_MAX_BYTES each) are the only files allowed beside the
 * manifest set, every signed file must still match its hash, the package's own module.json must declare
 * `"edition": "premium"`, and LICENSEE.json must name the manifest's module and version. Without $copy nothing changes.
 */
final class SafeExtractor
{
    public const ZIP_SIZE = 'MODULE_EXTRACT_ZIP_SIZE';
    public const ZIP_HASH = 'MODULE_EXTRACT_ZIP_HASH';
    public const OPEN = 'MODULE_EXTRACT_OPEN';
    public const STAGING = 'MODULE_EXTRACT_STAGING';
    public const ENTRY_PATH = 'MODULE_EXTRACT_ENTRY_PATH';
    public const ENTRY_DUPLICATE = 'MODULE_EXTRACT_ENTRY_DUPLICATE';
    public const ENTRY_TYPE = 'MODULE_EXTRACT_ENTRY_TYPE';
    public const TOO_MANY_FILES = 'MODULE_EXTRACT_TOO_MANY_FILES';
    public const FILE_TOO_LARGE = 'MODULE_EXTRACT_FILE_TOO_LARGE';
    public const UNPACKED_TOO_LARGE = 'MODULE_EXTRACT_UNPACKED_TOO_LARGE';
    public const RATIO = 'MODULE_EXTRACT_RATIO';
    public const FILE_SET = 'MODULE_EXTRACT_FILE_SET';
    public const FILE_HASH = 'MODULE_EXTRACT_FILE_HASH';
    public const MODULE_JSON = 'MODULE_EXTRACT_MODULE_JSON';
    public const WRITE = 'MODULE_EXTRACT_WRITE';
    public const ENTRY_TOO_LARGE = 'MODULE_PACKAGE_ENTRY_TOO_LARGE';
    public const SIZE_MISMATCH = 'MODULE_PACKAGE_SIZE_MISMATCH';
    public const LICENSEE = 'MODULE_EXTRACT_LICENSEE';

    /** Buyer mark file of a premium copy, inside "<Name>/"; it must be present in a marked copy. */
    public const LICENSEE_FILE = 'LICENSEE.json';

    /** Detached hub signature of the buyer mark, inside "<Name>/"; optional. */
    public const LICENSEE_SIG = 'LICENSEE.sig';

    /** Largest licensee file accepted. */
    public const LICENSEE_MAX_BYTES = 8192;

    private const TYPE_MASK = 0170000;
    private const TYPE_FILE = 0100000;
    private const TYPE_DIR = 0040000;
    private const DOS_DIR = 0x10;
    private const CHUNK = 65536;

    private int $maxZipBytes;
    private int $maxUnpackedBytes;
    private int $maxFiles;
    private int $maxFileBytes;
    private int $maxRatio;

    /**
     * Read package limits from config('core.module_packages.*'); $limits overrides any of them (tests use small limits).
     *
     * @param array<string, int> $limits keys: max_zip_bytes, max_unpacked_bytes, max_files, max_file_bytes, max_ratio
     */
    public function __construct(array $limits = [])
    {
        $read = static fn (string $key, int $default): int => (int) ($limits[$key] ?? config('core.module_packages.' . $key, $default));
        $this->maxZipBytes = $read('max_zip_bytes', 50 * 1024 * 1024);
        $this->maxUnpackedBytes = $read('max_unpacked_bytes', 200 * 1024 * 1024);
        $this->maxFiles = $read('max_files', 5000);
        $this->maxFileBytes = $read('max_file_bytes', 20 * 1024 * 1024);
        $this->maxRatio = $read('max_ratio', 100);
    }

    /**
     * Extract a verified package zip into "<stagingDir>/<Name>" and return that folder.
     *
     * The staging folder must be empty (it is created when missing). On failure it is left empty.
     *
     * @param array{zip_sha256: string, zip_size: int}|null $copy A premium per-grant copy named by the hub's signed
     * ticket answer; null = the zip must be exactly the signed package.
     *
     * @throws PackageVerificationException with one of the MODULE_EXTRACT_* codes
     */
    public function extract(string $zipPath, ReleaseManifest $manifest, string $stagingDir, ?array $copy = null): string
    {
        $stagingDir = rtrim($stagingDir, '/');
        $this->prepareStaging($stagingDir);
        $moduleDir = $stagingDir . '/' . $manifest->name;

        try {
            $this->checkZipFile($zipPath, $manifest, $copy);
            $zip = $this->open($zipPath);
            try {
                [$entries, $extras] = $this->plan($zip, $manifest, $copy !== null);
                $hashes = $this->write($zip, $entries, $stagingDir, $manifest->unpackedSize + array_sum($extras));
            } finally {
                $zip->close();
            }
            $this->verify($hashes, $manifest, $stagingDir, array_keys($extras));

            return $moduleDir;
        } catch (Throwable $e) {
            self::removeTree($moduleDir, $stagingDir);
            if ($e instanceof PackageVerificationException) {
                throw $e;
            }
            throw new PackageVerificationException(self::WRITE, 'extraction failed unexpectedly');
        }
    }

    /**
     * Remove a file, symlink or folder tree at $path without following symlinks.
     *
     * Refuses (returns false) when $path is not strictly inside $root, has "." / ".." segments, or its parent does not
     * resolve inside $root. A missing $path is a no-op that returns true.
     */
    public static function removeTree(string $path, string $root): bool
    {
        $root = rtrim($root, '/');
        $realRoot = $root === '' ? false : realpath($root);
        if ($realRoot === false || !str_starts_with($path, $root . '/')) {
            return false;
        }
        foreach (explode('/', substr($path, strlen($root) + 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        $parent = realpath(dirname($path));
        if ($parent === false || ($parent !== $realRoot && !str_starts_with($parent, $realRoot . '/'))) {
            return false;
        }

        return self::removeNode($path);
    }

    /**
     * Delete one node; folders are emptied first, symlinks are unlinked and never followed.
     */
    private static function removeNode(string $path): bool
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return true;
        }
        if (($stat['mode'] & self::TYPE_MASK) !== self::TYPE_DIR) {
            return @unlink($path);
        }
        $ok = true;
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $ok = self::removeNode($path . '/' . $entry) && $ok;
            }
        }

        return @rmdir($path) && $ok;
    }

    /**
     * Make sure the staging folder exists, is a real folder (not a link) and is empty.
     */
    private function prepareStaging(string $stagingDir): void
    {
        if ($stagingDir === '') {
            throw new PackageVerificationException(self::STAGING, 'staging folder is not set');
        }
        if (@lstat($stagingDir) === false && !@mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
            throw new PackageVerificationException(self::STAGING, 'staging folder cannot be created');
        }
        if (is_link($stagingDir) || !is_dir($stagingDir)) {
            throw new PackageVerificationException(self::STAGING, 'staging path is not a folder');
        }
        $entries = @scandir($stagingDir);
        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            throw new PackageVerificationException(self::STAGING, 'staging folder is not empty');
        }
    }

    /**
     * Compare the zip file size and sha256 with the manifest (or with the ticket's copy description) before opening it.
     *
     * @param array{zip_sha256: string, zip_size: int}|null $copy
     */
    private function checkZipFile(string $zipPath, ReleaseManifest $manifest, ?array $copy = null): void
    {
        $expectedSize = $copy !== null ? (int) ($copy['zip_size'] ?? -1) : $manifest->zipSize;
        $expectedHash = $copy !== null ? (string) ($copy['zip_sha256'] ?? '') : $manifest->zipSha256;
        clearstatcache(true, $zipPath);
        if (is_link($zipPath) || !is_file($zipPath)) {
            throw new PackageVerificationException(self::ZIP_SIZE, 'package zip is not a regular file');
        }
        $size = filesize($zipPath);
        if ($size === false || $size !== $expectedSize) {
            throw new PackageVerificationException(self::ZIP_SIZE, 'package zip size differs from the manifest');
        }
        if ($size > $this->maxZipBytes) {
            throw new PackageVerificationException(self::ZIP_SIZE, 'package zip is above max_zip_bytes');
        }
        $hash = hash_file('sha256', $zipPath);
        if ($hash === false || $expectedHash === '' || !hash_equals($expectedHash, $hash)) {
            throw new PackageVerificationException(self::ZIP_HASH, 'package zip sha256 differs from the manifest');
        }
    }

    /**
     * Open the archive read-only with consistency checks.
     */
    private function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
        if ($result !== true) {
            throw new PackageVerificationException(self::OPEN, 'package zip cannot be opened (libzip error ' . (int) $result . ')');
        }

        return $zip;
    }

    /**
     * Validate every entry before anything is written and return the extraction plan and the licensee files.
     *
     * @param bool $marked The caller names a premium per-grant copy, so the licensee files may sit beside the manifest set.
     *
     * @return array{0: list<array{index: int, path: string, dir: bool, size: int}>, 1: array<string, int>} plan, and licensee path => size
     */
    private function plan(ZipArchive $zip, ReleaseManifest $manifest, bool $marked = false): array
    {
        $count = $zip->count();
        if ($count > $this->maxFiles) {
            throw new PackageVerificationException(self::TOO_MANY_FILES, 'package has more than ' . $this->maxFiles . ' entries');
        }

        $entries = [];
        $explicit = [];
        $folded = [];
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $label = 'entry #' . ($i + 1);
            $raw = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if (!is_string($raw) || !is_array($stat)) {
                throw new PackageVerificationException(self::OPEN, $label . ' cannot be read');
            }
            $isDir = str_ends_with($raw, '/');
            $path = $isDir ? substr($raw, 0, -1) : $raw;
            $reason = self::unsafePathReason($path, $manifest->name, $isDir);
            if ($reason !== null) {
                throw new PackageVerificationException(self::ENTRY_PATH, $label . ' ' . $reason);
            }

            $this->checkType($zip, $i, $stat, $isDir, $label);

            $key = mb_strtolower($path, 'UTF-8');
            if (isset($explicit[$key])) {
                throw new PackageVerificationException(self::ENTRY_DUPLICATE, $label . ' duplicates another entry ignoring case');
            }
            $explicit[$key] = true;
            $this->claimPath($folded, $path, $isDir, $label);

            $size = (int) $stat['size'];
            if (!$isDir) {
                if ($size > $this->maxFileBytes) {
                    throw new PackageVerificationException(self::FILE_TOO_LARGE, $label . ' is above max_file_bytes');
                }
                $total += $size;
                if ($total > $this->maxUnpackedBytes) {
                    throw new PackageVerificationException(self::UNPACKED_TOO_LARGE, 'package unpacks above max_unpacked_bytes');
                }
                $compressed = (int) $stat['comp_size'];
                if ($size > 0 && ($compressed <= 0 || $size > $compressed * $this->maxRatio)) {
                    throw new PackageVerificationException(self::RATIO, $label . ' compression ratio is above max_ratio');
                }
            }

            $entries[] = ['index' => $i, 'path' => $path, 'dir' => $isDir, 'size' => $size];
        }

        $files = [];
        foreach ($entries as $entry) {
            if (!$entry['dir']) {
                $files[$entry['path']] = true;
            }
        }
        $expected = array_fill_keys(array_map('strval', array_keys($manifest->files)), true);
        $extras = $marked ? $this->licenseeFiles(array_diff_key($files, $expected), $entries, $manifest->name) : [];
        if (array_diff_key($files, $expected, $extras) !== []) {
            throw new PackageVerificationException(self::FILE_SET, 'package has file(s) the manifest does not list');
        }
        if (array_diff_key($expected, $files) !== []) {
            throw new PackageVerificationException(self::FILE_SET, 'package misses file(s) the manifest lists');
        }

        if ($total - array_sum($extras) !== $manifest->unpackedSize) {
            throw new PackageVerificationException(self::SIZE_MISMATCH, 'package unpacked size differs from the manifest');
        }

        return [$entries, $extras];
    }

    /**
     * The licensee files of a marked copy among the files the manifest does not list, as path => size.
     *
     * Any other unlisted file is left for the caller's file-set refusal. LICENSEE.json must be there.
     *
     * @param array<string, true>                                         $unlisted
     * @param list<array{index: int, path: string, dir: bool, size: int}> $entries
     *
     * @return array<string, int>
     */
    private function licenseeFiles(array $unlisted, array $entries, string $name): array
    {
        $allowed = [$name . '/' . self::LICENSEE_FILE => true, $name . '/' . self::LICENSEE_SIG => true];
        if (array_diff_key($unlisted, $allowed) !== []) {
            throw new PackageVerificationException(self::FILE_SET, 'package has file(s) the manifest does not list');
        }
        if (!isset($unlisted[$name . '/' . self::LICENSEE_FILE])) {
            throw new PackageVerificationException(self::LICENSEE, 'marked package has no ' . self::LICENSEE_FILE);
        }

        $extras = [];
        foreach ($entries as $entry) {
            if (!$entry['dir'] && isset($unlisted[$entry['path']])) {
                if ($entry['size'] > self::LICENSEE_MAX_BYTES) {
                    throw new PackageVerificationException(self::LICENSEE, 'a licensee file is too large');
                }
                $extras[$entry['path']] = $entry['size'];
            }
        }

        return $extras;
    }

    /**
     * Allow only regular files and folders: no symlinks, devices, encrypted or exotically compressed entries.
     *
     * @param array<string, mixed> $stat
     */
    private function checkType(ZipArchive $zip, int $index, array $stat, bool $isDir, string $label): void
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            throw new PackageVerificationException(self::ENTRY_TYPE, $label . ' attributes cannot be read');
        }
        if ($opsys === ZipArchive::OPSYS_UNIX) {
            $type = ($attr >> 16) & self::TYPE_MASK;
            if ($type !== 0 && $type !== ($isDir ? self::TYPE_DIR : self::TYPE_FILE)) {
                throw new PackageVerificationException(self::ENTRY_TYPE, $label . ' is not a regular file or folder');
            }
        }
        if (!$isDir && ($attr & self::DOS_DIR) !== 0) {
            throw new PackageVerificationException(self::ENTRY_TYPE, $label . ' is marked as a folder');
        }
        if ($isDir && (int) $stat['size'] !== 0) {
            throw new PackageVerificationException(self::ENTRY_TYPE, $label . ' is a folder with content');
        }
        if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
            throw new PackageVerificationException(self::ENTRY_TYPE, $label . ' is encrypted');
        }
        if (!in_array((int) $stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
            throw new PackageVerificationException(self::ENTRY_TYPE, $label . ' uses an unsupported compression method');
        }
    }

    /**
     * Record a path and its parent folders case-folded; refuse a file/folder clash or two spellings of one path.
     *
     * @param array<string, array{0: string, 1: bool}> $folded case-folded path => [exact path, is folder]
     */
    private function claimPath(array &$folded, string $path, bool $isDir, string $label): void
    {
        $segments = explode('/', $path);
        $current = '';
        foreach ($segments as $n => $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $dir = $isDir || $n < count($segments) - 1;
            $key = mb_strtolower($current, 'UTF-8');
            if (isset($folded[$key]) && ($folded[$key][0] !== $current || $folded[$key][1] !== $dir || !$dir)) {
                throw new PackageVerificationException(self::ENTRY_DUPLICATE, $label . ' clashes with another entry');
            }
            $folded[$key] = [$current, $dir];
        }
    }

    /**
     * Return why an entry path is unsafe to extract, or null when it is safe.
     */
    private static function unsafePathReason(string $path, string $name, bool $isDir): ?string
    {
        if ($path === '') {
            return 'has an empty name';
        }
        if (str_contains($path, "\0")) {
            return 'contains NUL';
        }
        if (str_contains($path, '\\')) {
            return 'contains a backslash';
        }
        if (str_starts_with($path, '/')) {
            return 'is absolute';
        }
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return 'has a drive letter';
        }
        if (!mb_check_encoding($path, 'UTF-8')) {
            return 'is not valid UTF-8';
        }
        $segments = explode('/', $path);
        if (count($segments) > ReleaseManifest::MAX_PATH_SEGMENTS) {
            return 'has more than ' . ReleaseManifest::MAX_PATH_SEGMENTS . ' segments';
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'has an empty, "." or ".." segment';
            }
        }
        if ($segments[0] !== $name || (!$isDir && count($segments) < 2)) {
            return 'is outside "' . $name . '/"';
        }

        return null;
    }

    /**
     * Create folders and stream every file into a new file; return path => sha256 of the bytes written.
     *
     * @param list<array{index: int, path: string, dir: bool, size: int}> $entries
     * @return array<string, string>
     */
    private function write(ZipArchive $zip, array $entries, string $stagingDir, int $expectedTotal): array
    {
        $hashes = [];
        $total = 0;
        foreach ($entries as $entry) {
            if ($entry['dir']) {
                $this->ensureDir($stagingDir, $entry['path']);
                continue;
            }
            $this->ensureDir($stagingDir, dirname($entry['path']));
            $hashes[$entry['path']] = $this->streamFile($zip, $entry, $stagingDir . '/' . $entry['path']);
            $total += $entry['size'];
        }
        if ($total !== $expectedTotal) {
            throw new PackageVerificationException(self::SIZE_MISMATCH, 'bytes written differ from the manifest unpacked size');
        }

        return $hashes;
    }

    /**
     * Create each missing folder of a relative path (0755); refuse anything that is not a real folder.
     */
    private function ensureDir(string $base, string $relative): void
    {
        $current = $base;
        foreach (explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            $stat = @lstat($current);
            if ($stat === false) {
                if (!@mkdir($current, 0755) || !@chmod($current, 0755)) {
                    throw new PackageVerificationException(self::WRITE, 'cannot create a staging folder');
                }
            } elseif (($stat['mode'] & self::TYPE_MASK) !== self::TYPE_DIR) {
                throw new PackageVerificationException(self::WRITE, 'staging path is not a folder');
            }
        }
    }

    /**
     * Stream one entry into a new file, failing when it exceeds or misses its declared size.
     *
     * @param array{index: int, path: string, dir: bool, size: int} $entry
     */
    private function streamFile(ZipArchive $zip, array $entry, string $target): string
    {
        $in = $zip->getStreamIndex($entry['index']);
        if ($in === false) {
            throw new PackageVerificationException(self::WRITE, 'cannot read a package entry');
        }
        $out = @fopen($target, 'xb');
        if ($out === false) {
            fclose($in);
            throw new PackageVerificationException(self::WRITE, 'cannot create a staging file');
        }

        $context = hash_init('sha256');
        $written = 0;
        try {
            while (!feof($in)) {
                // Never ask for more than one byte past the declared size, so an overrun is caught before it is written.
                $chunk = @fread($in, min(self::CHUNK, $entry['size'] - $written + 1));
                if ($chunk === false) {
                    throw new PackageVerificationException(self::WRITE, 'cannot read a package entry');
                }
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $entry['size']) {
                    throw new PackageVerificationException(self::ENTRY_TOO_LARGE, 'a package entry exceeds its declared size');
                }
                if (@fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new PackageVerificationException(self::WRITE, 'cannot write a staging file');
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($written !== $entry['size']) {
            throw new PackageVerificationException(self::WRITE, 'a package entry is shorter than its declared size');
        }
        if (!@chmod($target, 0644)) {
            throw new PackageVerificationException(self::WRITE, 'cannot set staging file permissions');
        }

        return hash_final($context);
    }

    /**
     * Confirm the staged tree holds exactly the manifest files with matching hashes and a matching module.json.
     *
     * @param array<string, string> $hashes   path => sha256 of the bytes written
     * @param list<string> $licensee licensee files of a marked copy, already planned
     */
    private function verify(array $hashes, ReleaseManifest $manifest, string $stagingDir, array $licensee = []): void
    {
        foreach ($manifest->files as $path => $hash) {
            $path = (string) $path;
            if (!isset($hashes[$path]) || !hash_equals($hash, $hashes[$path])) {
                throw new PackageVerificationException(self::FILE_HASH, 'a file hash differs from the manifest');
            }
        }

        $found = [];
        $this->scan($stagingDir, '', $found);
        ksort($found);
        $expected = array_fill_keys(array_map('strval', array_keys($manifest->files)), true) + array_fill_keys($licensee, true);
        ksort($expected);
        if ($found !== $expected) {
            throw new PackageVerificationException(self::FILE_SET, 'staged files differ from the manifest');
        }

        $jsonPath = $manifest->name . '/module.json';
        if (!isset($expected[$jsonPath])) {
            throw new PackageVerificationException(self::MODULE_JSON, 'package has no module.json');
        }
        $json = json_decode((string) @file_get_contents($stagingDir . '/' . $jsonPath), true);
        if (!is_array($json) || ($json['name'] ?? null) !== $manifest->name || ($json['version'] ?? null) !== $manifest->version) {
            throw new PackageVerificationException(self::MODULE_JSON, 'module.json name or version differs from the manifest');
        }

        if ($licensee !== []) {
            $this->verifyLicensee($json, $manifest, $stagingDir);
        }
    }

    /**
     * A marked copy is accepted only for a package whose signed module.json is `premium`, and its LICENSEE.json must
     * be a JSON object naming the manifest's module and version.
     *
     * @param array<string, mixed> $moduleJson the verified module.json
     */
    private function verifyLicensee(array $moduleJson, ReleaseManifest $manifest, string $stagingDir): void
    {
        if (ModuleEdition::of($manifest->name, $moduleJson) !== ModuleEdition::PREMIUM) {
            throw new PackageVerificationException(self::LICENSEE, 'only a premium package may carry a buyer mark');
        }
        $mark = json_decode((string) @file_get_contents($stagingDir . '/' . $manifest->name . '/' . self::LICENSEE_FILE), true);
        if (!is_array($mark) || array_is_list($mark) || ($mark['module'] ?? null) !== $manifest->name || ($mark['version'] ?? null) !== $manifest->version) {
            throw new PackageVerificationException(self::LICENSEE, self::LICENSEE_FILE . ' does not name this module version');
        }
    }

    /**
     * Collect relative file paths of the staged tree; anything but files and folders is refused.
     *
     * @param array<string, true> $found
     */
    private function scan(string $base, string $relative, array &$found): void
    {
        $dir = $relative === '' ? $base : $base . '/' . $relative;
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $relative === '' ? $entry : $relative . '/' . $entry;
            $stat = @lstat($base . '/' . $path);
            $type = $stat === false ? -1 : ($stat['mode'] & self::TYPE_MASK);
            if ($type === self::TYPE_DIR) {
                $this->scan($base, $path, $found);
            } elseif ($type === self::TYPE_FILE) {
                $found[$path] = true;
            } else {
                throw new PackageVerificationException(self::ENTRY_TYPE, 'staged tree holds a non-regular file');
            }
        }
    }
}
