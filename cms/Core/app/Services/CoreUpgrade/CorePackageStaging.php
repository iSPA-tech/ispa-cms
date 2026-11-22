<?php

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use ZipArchive;

/**
 * Safe unzip of a core package into an empty staging folder.
 *
 * SafeExtractor is built for module packages (one folder, every file listed in the signed manifest); a core zip is the
 * whole tree at its root and the signed manifest lists only `core-manifest.json`, so this class applies the same
 * rules in its own way: safe relative paths, regular files and folders only, no case-folded duplicates, count / size /
 * ratio caps, then the inner manifest must match the signed hash and every `checked` file must match its hash.
 * The zip itself was already bound to the signed manifest by size and sha256 before this runs.
 */
final class CorePackageStaging
{
    public const MAX_FILES = 150000;

    public const MAX_UNPACKED_BYTES = 2 * 1024 * 1024 * 1024;

    public const MAX_RATIO = 300;

    /**
     * Extract and verify; returns the parsed inner manifest. Removes nothing on failure (the caller deletes staging).
     *
     * @throws CoreUpgradeException
     */
    public function extract(string $zipPath, ReleaseManifest $signed, string $staging): CoreManifest
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new CoreUpgradeException('CORE_PACKAGE_OPEN', 'The core package zip cannot be opened.');
        }
        try {
            if ($zip->count() > self::MAX_FILES) {
                throw new CoreUpgradeException('CORE_PACKAGE_LIMITS', 'The core package has too many entries.');
            }
            $plan = $this->plan($zip);
            if (! is_dir($staging) && ! @mkdir($staging, 0700, true)) {
                throw new CoreUpgradeException('CORE_STAGING', 'Cannot create the staging folder.');
            }
            foreach ($plan as $entry) {
                $this->write($zip, $entry, $staging);
            }
        } finally {
            $zip->close();
        }

        $inner = $staging.'/'.CoreManifest::FILE;
        $expected = $signed->files[CoreManifest::FILE] ?? null;
        if (! is_file($inner) || ! is_string($expected) || ! hash_equals($expected, (string) hash_file('sha256', $inner))) {
            throw new CoreUpgradeException('CORE_MANIFEST_MISMATCH', 'core-manifest.json in the package does not match the signed manifest.');
        }
        $manifest = CoreManifest::fromFile($inner);
        foreach ($manifest->checked as $path => $hash) {
            $file = $staging.'/'.$path;
            if (! is_file($file) || is_link($file) || ! hash_equals($hash, (string) hash_file('sha256', $file))) {
                throw new CoreUpgradeException('CORE_FILE_HASH', 'A file in the package does not match core-manifest.json: '.$path);
            }
        }

        return $manifest;
    }

    /**
     * @return list<array{index: int, path: string, dir: bool, mode: int}>
     */
    private function plan(ZipArchive $zip): array
    {
        $plan = [];
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->count(); $i++) {
            $raw = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if (! is_string($raw) || ! is_array($stat)) {
                throw new CoreUpgradeException('CORE_PACKAGE_OPEN', 'A package entry cannot be read.');
            }
            $dir = str_ends_with($raw, '/');
            $path = $dir ? substr($raw, 0, -1) : $raw;
            if (! $this->safePath($path)) {
                throw new CoreUpgradeException('CORE_PACKAGE_ENTRY_PATH', 'The package has an unsafe path: '.$path);
            }
            $opsys = 0;
            $attr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            $mode = $opsys === ZipArchive::OPSYS_UNIX ? ($attr >> 16) & 0xFFFF : 0;
            $type = $mode & 0170000;
            if ($type !== 0 && $type !== ($dir ? 0040000 : 0100000)) {
                throw new CoreUpgradeException('CORE_PACKAGE_ENTRY_TYPE', 'The package has a link or special file: '.$path);
            }
            $key = mb_strtolower($path, 'UTF-8');
            if (isset($seen[$key])) {
                throw new CoreUpgradeException('CORE_PACKAGE_DUPLICATE', 'The package has a duplicate path: '.$path);
            }
            $seen[$key] = true;
            if (! $dir) {
                $size = (int) $stat['size'];
                $total += $size;
                $compressed = (int) $stat['comp_size'];
                if ($total > self::MAX_UNPACKED_BYTES || ($size > 0 && ($compressed <= 0 || $size > $compressed * self::MAX_RATIO))) {
                    throw new CoreUpgradeException('CORE_PACKAGE_LIMITS', 'The package is larger or more compressed than allowed.');
                }
            }
            $plan[] = ['index' => $i, 'path' => $path, 'dir' => $dir, 'mode' => $mode & 0777];
        }

        return $plan;
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{index: int, path: string, dir: bool, mode: int} $entry
     */
    private function write(ZipArchive $zip, array $entry, string $staging): void
    {
        $target = $staging.'/'.$entry['path'];
        if ($entry['dir']) {
            if (! is_dir($target) && ! @mkdir($target, 0755, true)) {
                throw new CoreUpgradeException('CORE_STAGING', 'Cannot create '.$entry['path']);
            }

            return;
        }
        $parent = dirname($target);
        if (! is_dir($parent) && ! @mkdir($parent, 0755, true)) {
            throw new CoreUpgradeException('CORE_STAGING', 'Cannot create '.dirname($entry['path']));
        }
        $in = $zip->getStreamIndex($entry['index']);
        $out = $in === false ? false : @fopen($target, 'xb');
        if ($in === false || $out === false) {
            throw new CoreUpgradeException('CORE_STAGING', 'Cannot extract '.$entry['path']);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        @chmod($target, $entry['mode'] !== 0 ? ($entry['mode'] & 0755) | 0600 : 0644);
    }
}
