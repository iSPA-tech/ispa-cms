<?php

namespace Cms\Core\Services\CoreUpgrade;

use InvalidArgumentException;

/**
 * The installed core manifest (`core-manifest.json` at the tree root), written by the release script.
 *
 * Format 1: version, php_min, built_at, composer_lock_sha256, checked (posix path => sha256), replaced (paths).
 * Read strictly: a malformed or unsafe manifest is refused, never partly trusted.
 */
final class CoreManifest
{
    public const FILE = 'core-manifest.json';

    /**
     * @param  array<string, string>  $checked  posix path => lowercase sha256
     * @param  list<string>  $replaced  paths always replaced from the package, never integrity-checked
     */
    public function __construct(
        public readonly string $version,
        public readonly string $phpMin,
        public readonly array $checked,
        public readonly array $replaced,
        public readonly string $composerLockSha256,
    ) {}

    /**
     * The manifest of this site, or null when the site was not installed from a core package.
     *
     * @param  string|null  $base  Tree root (default: base_path()).
     *
     * @throws InvalidArgumentException When the file exists but is not a valid manifest.
     */
    public static function installed(?string $base = null): ?self
    {
        $path = rtrim($base ?? base_path(), '/\\').'/'.self::FILE;

        return is_file($path) ? self::fromFile($path) : null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new InvalidArgumentException('Core manifest cannot be read: '.$path);
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Core manifest is not a JSON object.');
        }
        if (($data['format'] ?? null) !== 1) {
            throw new InvalidArgumentException('Core manifest format is not supported.');
        }
        foreach (['version', 'php_min', 'checked', 'replaced'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException('Core manifest is missing "'.$key.'".');
            }
        }
        if (! is_string($data['version']) || $data['version'] === '' || ! is_string($data['php_min'])) {
            throw new InvalidArgumentException('Core manifest version or php_min is invalid.');
        }
        $lock = $data['composer_lock_sha256'] ?? '';
        if (! is_string($lock) || ($lock !== '' && ! self::isHash($lock))) {
            throw new InvalidArgumentException('Core manifest composer_lock_sha256 is invalid.');
        }
        if (! is_array($data['checked']) || ! is_array($data['replaced'])) {
            throw new InvalidArgumentException('Core manifest checked/replaced have the wrong type.');
        }

        $checked = [];
        foreach ($data['checked'] as $file => $hash) {
            $file = (string) $file;
            if (! self::isSafePath($file)) {
                throw new InvalidArgumentException('Core manifest has an unsafe path: '.$file);
            }
            if (! is_string($hash) || ! self::isHash($hash)) {
                throw new InvalidArgumentException('Core manifest has an invalid hash for '.$file);
            }
            $checked[$file] = $hash;
        }
        $replaced = [];
        foreach ($data['replaced'] as $file) {
            if (! is_string($file) || ! self::isSafePath($file)) {
                throw new InvalidArgumentException('Core manifest has an unsafe replaced path.');
            }
            $replaced[] = $file;
        }

        return new self($data['version'], $data['php_min'], $checked, $replaced, $lock);
    }

    private static function isHash(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    private static function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
