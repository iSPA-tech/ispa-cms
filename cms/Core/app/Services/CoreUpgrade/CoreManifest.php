<?php

namespace Cms\Core\Services\CoreUpgrade;

use InvalidArgumentException;

/**
 * The installed core manifest (`core-manifest.json` at the tree root), written by the release script.
 *
 * Format 1: version, php_min, built_at, composer_lock_sha256, checked (posix path => sha256), replaced (paths).
 * Read strictly: a malformed or unsafe manifest is refused, never partly trusted.
 *
 * Optional `origin`: `package` (default: the site was installed from a core package, the release script wrote it) or
 * `deploy` (a CI-deployed release; the deploy hook `hub-modules.sh after` wrote it). For `deploy`, `checked` lists only
 * the release files whose path is in the public core build's file list (minus files the site regenerates), and an
 * upgrade from a core package writes only package paths, overwrites an existing file only when `checked` lists it,
 * removes only `checked` files the new package no longer has, and never swaps vendor/ or the built assets
 * ({@see CoreFileSwapper::plan()}). Optional `upgraded_from`: the version an admin upgrade replaced (deploy origin).
 */
final class CoreManifest
{
    public const FILE = 'core-manifest.json';

    public const ORIGIN_PACKAGE = 'package';

    public const ORIGIN_DEPLOY = 'deploy';

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
        public readonly string $origin = self::ORIGIN_PACKAGE,
        public readonly ?string $upgradedFrom = null,
    ) {}

    /** A CI-deployed release whose manifest the deploy hook wrote: the deploy owns vendor/, builds and site files. */
    public function fromDeploy(): bool
    {
        return $this->origin === self::ORIGIN_DEPLOY;
    }

    /**
     * The manifest as written to `core-manifest.json` (format 1; `origin` and `upgraded_from` only when set).
     *
     * @return array<string, mixed>
     */
    public function toArray(string $builtAt): array
    {
        $data = [
            'format' => 1, 'version' => $this->version, 'php_min' => $this->phpMin, 'built_at' => $builtAt,
            'composer_lock_sha256' => $this->composerLockSha256,
        ];
        if ($this->origin !== self::ORIGIN_PACKAGE) {
            $data['origin'] = $this->origin;
        }
        if ($this->upgradedFrom !== null) {
            $data['upgraded_from'] = $this->upgradedFrom;
        }
        $checked = $this->checked;
        ksort($checked, SORT_STRING);

        return $data + ['checked' => $checked === [] ? (object) [] : $checked, 'replaced' => $this->replaced];
    }

    /**
     * The manifest of this site, or null when the site was not installed from a core package.
     *
     * @param  string|null  $base  Tree root (default: {@see self::defaultBase()}).
     *
     * @throws InvalidArgumentException When the file exists but is not a valid manifest.
     */
    public static function installed(?string $base = null): ?self
    {
        $path = rtrim($base ?? self::defaultBase(), '/\\').'/'.self::FILE;

        return is_file($path) ? self::fromFile($path) : null;
    }

    /**
     * Where the installed manifest is read when no base is given: base_path(), unless `ispa-cms.core_manifest_base`
     * is set. That key is a test seam only (tests point it at a temp folder so a real manifest is never read or
     * written); production never sets it.
     */
    public static function defaultBase(): string
    {
        $base = config('ispa-cms.core_manifest_base');

        return is_string($base) && $base !== '' ? $base : base_path();
    }

    /**
     * The installed iSPA CMS version: the manifest's, else the VERSION file (`ispa-cms.app_version`). An admin upgrade
     * on a CI-deployed site rewrites the manifest but never VERSION (deploy-owned, {@see CoreFileSwapper::DEPLOY_OWNED_FILES}).
     * An unreadable manifest falls back to VERSION instead of throwing.
     */
    public static function installedVersion(?string $base = null): string
    {
        try {
            $manifest = self::installed($base);
        } catch (InvalidArgumentException) {
            $manifest = null;
        }

        return $manifest?->version ?? (string) config('ispa-cms.app_version', '');
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
        $origin = $data['origin'] ?? self::ORIGIN_PACKAGE;
        if (! in_array($origin, [self::ORIGIN_PACKAGE, self::ORIGIN_DEPLOY], true)) {
            throw new InvalidArgumentException('Core manifest origin is not supported.');
        }
        $from = $data['upgraded_from'] ?? null;
        if ($from !== null && (! is_string($from) || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/', $from) !== 1)) {
            throw new InvalidArgumentException('Core manifest upgraded_from is invalid.');
        }

        return new self($data['version'], $data['php_min'], $checked, $replaced, $lock, $origin, $from);
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
