<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Stored choice of the module status activator (spec 2026-11-22 module install from the admin).
 *
 * config/modules.php picks the activator while configuration loads, before the database is available, so the choice
 * made in the admin is kept in a small file: `storage/app/modules/activator` holding the activator name. storage/ is
 * shared by every release of a deployed site and never tracked by git, so the choice survives deploys. The web
 * process writes it when the Webmaster switches module install on ({@see \Cms\Core\Services\Modules\Install\ModuleInstallPolicy});
 * it is never removed by the web process, because once module status lives in the database the file statuses are no
 * longer current.
 *
 * Order: a non-empty `MODULES_ACTIVATOR` in `.env` always wins (the server decides, `file` forces the file
 * activator); else the marker (`database`); else `file`. With `config:cache` the value is read when the cache is
 * built, so whoever writes the marker must rebuild the config cache.
 */
final class ActivatorMarker
{
    /** Marker path relative to the storage folder. */
    public const FILE = 'app/modules/activator';

    /** The only value the marker may hold. */
    public const DATABASE = 'database';

    /** Activator used without `.env` and without marker. */
    public const DEFAULT = 'file';

    /** Test override for the marker path. */
    private static ?string $pathOverride = null;

    /**
     * Activator name for config/modules.php.
     *
     * @param mixed $env Raw `MODULES_ACTIVATOR` value (null when unset).
     */
    public static function activator(mixed $env): string
    {
        $forced = self::forced($env);
        if ($forced !== null) {
            return $forced;
        }

        return self::read() === self::DATABASE ? self::DATABASE : self::DEFAULT;
    }

    /**
     * The activator `.env` forces, or null when `MODULES_ACTIVATOR` is unset or empty.
     *
     * @param mixed $env Raw `MODULES_ACTIVATOR` value.
     */
    public static function forced(mixed $env): ?string
    {
        return is_string($env) && trim($env) !== '' ? trim($env) : null;
    }

    /**
     * Marker content (trimmed), or null when there is no readable marker.
     */
    public static function read(): ?string
    {
        $path = self::path();
        if (! is_file($path)) {
            return null;
        }
        $value = @file_get_contents($path);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Store the database activator choice (write to a temporary file, then rename, so a reader never sees half a file).
     *
     * @throws \RuntimeException When the marker cannot be written.
     */
    public static function writeDatabase(): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('The module activator marker folder could not be created.');
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, self::DATABASE . "\n") === false || ! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException('The module activator marker could not be written.');
        }
    }

    /**
     * Absolute marker path.
     */
    public static function path(): string
    {
        return self::$pathOverride ?? storage_path(self::FILE);
    }

    /**
     * Point the marker at another file (tests only).
     */
    public static function setPath(?string $path): void
    {
        self::$pathOverride = $path;
    }
}
