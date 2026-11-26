<?php

namespace Cms\Core\Services\CoreUpgrade;

use InvalidArgumentException;

/**
 * The files shared between the admin (web user) and the upgrade runner
 * for a one-click core upgrade (spec 4.1, 4.3): the request file the admin writes, the heartbeat the runner
 * writes, and the lock the runner holds while it works.
 *
 * Plain PHP with no Laravel symbol: the runner reads it before Laravel boots. Files are written `0664` in a
 * `0775` folder by temp file + rename, so both Unix users (sharing `storage/` through a group or ACLs) can
 * read and remove what the other wrote.
 */
final class CoreRunnerTick
{
    public const DIR = 'storage/app/ispa';

    public const REQUEST_FILE = self::DIR.'/core-upgrade-request.json';

    public const HEARTBEAT_FILE = self::DIR.'/core-runner.json';

    public const LOCK_FILE = self::DIR.'/core-runner.lock';

    /** A heartbeat older than this means no runner is set up or it stopped. */
    public const HEARTBEAT_FRESH_SECONDS = 180;

    public const REQUEST_MAX_BYTES = 4096;

    public const ACTIONS = ['upgrade', 'restore'];

    private const FORMAT = 1;

    private const UUID_PATTERN = '/^[0-9a-f-]{36}$/';

    private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/';

    private readonly string $base;

    /**
     * @param int|null $euid Effective uid of this process (default posix_geteuid(); tests pass one).
     */
    public function __construct(string $base, private readonly ?int $euid = null)
    {
        $this->base = rtrim($base, '/\\');
    }

    /**
     * Whether the code folder could be changed by someone other than `$uid`: the base, or the runner folder
     * `.ispa-runner` or one of its folders (when they exist), is a link, not a folder, owned by another user (when
     * `$uid` is given), or writable by group or others (`mode & 0022`). Folders that cannot be read are skipped, so
     * the web side (which cannot enter `.ispa-runner`) checks what it can see.
     */
    public static function codeDirShared(string $base, ?int $uid): bool
    {
        $base = rtrim($base, '/\\');
        foreach (['', '/.ispa-runner', '/.ispa-runner/upgrade-staging', '/.ispa-runner/upgrade-backups'] as $i => $suffix) {
            $path = $base.$suffix;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false) {
                if ($i === 0) {
                    return true;
                }

                continue;
            }
            if (($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0022) !== 0 || ($uid !== null && $stat['uid'] !== $uid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paths the upgrade writes into that the running user cannot write: the base, the top folders of the
     * installed and new manifests (per-site paths excluded), and the runner folder. The runner folder is
     * never created here: when it is missing its nearest existing parent is checked instead.
     *
     * @return list<string>
     */
    public static function unwritablePaths(string $base, CoreManifest $installed, ?CoreManifest $new = null): array
    {
        $base = rtrim($base, '/\\');
        $bad = [];
        if (! is_writable($base)) {
            $bad[] = '.';
        }
        $tops = [];
        foreach (array_merge(array_keys($installed->checked), array_keys($new?->checked ?? []), $installed->replaced) as $path) {
            $path = (string) $path;
            if (CoreFileSwapper::isProtected($path)) {
                continue;
            }
            $tops[explode('/', $path)[0]] = true;
        }
        foreach (array_keys($tops) as $top) {
            $full = $base.'/'.$top;
            if (file_exists($full) && ! is_writable($full)) {
                $bad[] = (string) $top;
            }
        }

        for ($dir = self::DIR; $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
            if (file_exists($base.'/'.$dir)) {
                if (! is_writable($base.'/'.$dir)) {
                    $bad[] = $dir;
                }
                break;
            }
        }

        return $bad;
    }

    /**
     * The uid this process runs as (the runner): the effective uid, or without posix the owner of the base's `artisan`.
     */
    public static function currentUid(string $base): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $owner = @fileowner(rtrim($base, '/\\').'/artisan');

        return $owner === false ? null : $owner;
    }

    /**
     * Whether `$path` is a regular file (a link never counts) owned by `$uid`: the only kind of runner file
     * (state file, backup tar) the runner trusts, since `storage/` is writable by the web user too.
     */
    public static function ownedRegularFile(string $path, ?int $uid): bool
    {
        if ($uid === null) {
            return false;
        }
        $stat = @lstat($path);

        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['uid'] === $uid;
    }

    /**
     * Whether `$path` is a real folder (not a link) owned by `$uid`.
     */
    public static function ownedDirectory(string $path, ?int $uid): bool
    {
        if ($uid === null) {
            return false;
        }
        $stat = @lstat($path);

        return is_array($stat) && ($stat['mode'] & 0170000) === 0040000 && $stat['uid'] === $uid;
    }

    /**
     * The `current` symlink of a release layout pointing at this tree, or null for a flat install.
     *
     * @return array{link: string, previous: string}|null
     */
    public static function releaseLink(string $base): ?array
    {
        $base = rtrim($base, '/\\');
        $real = realpath($base);
        foreach ([dirname($base), dirname($base, 2)] as $dir) {
            $link = $dir.'/current';
            if (is_link($link) && realpath($link) === $real) {
                return ['link' => $link, 'previous' => $base];
            }
        }

        return null;
    }

    /**
     * Whether a runner may work on this tree: it does not run as root, the tree was installed from a core package,
     * it is a flat install and every path the upgrade writes is writable.
     */
    /**
     * Whether this process can be the runner itself: it may run ({@see self::canRun()}) and owns the code folder,
     * which nobody else can write. True on simple hosting, where the web user owns the code: an upgrade asked from
     * the admin then starts from the request ({@see CoreRunnerLauncher}) and needs no runner cron.
     */
    public static function selfRunner(string $base, ?int $euid = null): bool
    {
        $euid ??= function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($euid === null) {
            return false;
        }

        return (new self($base, $euid))->canRun() && ! self::codeDirShared($base, $euid);
    }

    /**
     * Remove group and others write access from the code folder (and `.ispa-runner`) when this process owns it:
     * unpacking a zip or `chmod -R 775` often leaves the folder group-writable, which the runner refuses
     * (CORE_CODE_DIR_SHARED). Never for root, a link or a release layout. Returns whether anything changed.
     */
    public static function tightenBase(string $base, ?int $euid = null): bool
    {
        $euid ??= function_exists('posix_geteuid') ? posix_geteuid() : null;
        $base = rtrim($base, '/\\');
        if ($euid === null || $euid === 0 || self::releaseLink($base) !== null) {
            return false;
        }
        $changed = false;
        foreach (['', '/'.CoreUpgrader::RUNNER_DIR] as $suffix) {
            $path = $base.$suffix;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $euid || ($stat['mode'] & 0022) === 0) {
                continue;
            }
            $changed = @chmod($path, $stat['mode'] & 07755) || $changed;
        }

        return $changed;
    }

    public function canRun(): bool
    {
        // Never as root: one-click updates need a code owner that is not root.
        if (($this->euid ?? (function_exists('posix_geteuid') ? posix_geteuid() : null)) === 0) {
            return false;
        }
        // The web user can write `storage/app`: never work through a runner folder it replaced with a link.
        if (is_link($this->base.'/'.self::DIR)) {
            return false;
        }
        try {
            $installed = CoreManifest::installed($this->base);
        } catch (InvalidArgumentException) {
            return false;
        }

        // Behind a `current` link only a CI-deployed release (deploy-origin manifest), which is upgraded in place.
        return $installed !== null
            && ($installed->fromDeploy() || self::releaseLink($this->base) === null)
            && self::unwritablePaths($this->base, $installed) === [];
    }

    /**
     * What the runner does this minute: `cannot_run`, or (after writing the heartbeat) `run` when a request
     * waits and `idle` otherwise.
     */
    public function preBoot(?int $now = null): string
    {
        if (! $this->canRun()) {
            return 'cannot_run';
        }
        $this->writeHeartbeat($now);

        return $this->hasRequestFile() ? 'run' : 'idle';
    }

    public function writeHeartbeat(?int $now = null): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? '') : '';

        self::writeJson($this->base, self::HEARTBEAT_FILE, [
            'format' => self::FORMAT,
            'at' => $now ?? time(),
            'user' => $user !== '' ? $user : get_current_user(),
            'uid' => $uid,
            'php_version' => PHP_VERSION,
        ]);
    }

    /**
     * The runner heartbeat when it is valid and at most 180 seconds old.
     *
     * @return array{at: int, user: string, uid: int, php_version: string}|null
     */
    public static function freshHeartbeat(string $base, ?int $now = null): ?array
    {
        $data = self::readJson(rtrim($base, '/\\').'/'.self::HEARTBEAT_FILE);
        if ($data === null || ($data['format'] ?? null) !== self::FORMAT
            || ! is_int($data['at'] ?? null) || ! is_string($data['user'] ?? null)
            || ! is_int($data['uid'] ?? null) || ! is_string($data['php_version'] ?? null)) {
            return null;
        }
        $age = ($now ?? time()) - $data['at'];
        if ($age > self::HEARTBEAT_FRESH_SECONDS || $age < -self::HEARTBEAT_FRESH_SECONDS) {
            return null;
        }

        return ['at' => $data['at'], 'user' => $data['user'], 'uid' => $data['uid'], 'php_version' => $data['php_version']];
    }

    /**
     * Ask the runner to upgrade (to `$version`, null = latest) or restore, for the background task `$taskUuid`.
     *
     * @param string $webPhp The PHP binary of the web user, so the runner can check it matches.
     *
     * @throws InvalidArgumentException When the request would not read back as valid.
     */
    public static function writeRequest(string $base, string $action, string $taskUuid, ?string $version, string $webPhp, ?int $now = null): void
    {
        $data = [
            'format' => self::FORMAT,
            'action' => $action,
            'task_uuid' => $taskUuid,
            'version' => $version,
            'web_php' => $webPhp,
            'requested_at' => date('c', $now ?? time()),
        ];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (self::validRequest($data) === null || $json === false || strlen($json) > self::REQUEST_MAX_BYTES) {
            throw new InvalidArgumentException('Invalid core upgrade request.');
        }
        self::writeJson($base, self::REQUEST_FILE, $data);
    }

    /**
     * The waiting request, or null when there is none or it is invalid (bad JSON, too large, unknown
     * action or format, unsafe version, malformed task uuid).
     *
     * @return array{action: string, task_uuid: string, version: ?string, web_php: string, requested_at: string}|null
     */
    public function readRequest(): ?array
    {
        $data = self::readJson($this->base.'/'.self::REQUEST_FILE);

        return $data === null ? null : self::validRequest($data);
    }

    public function hasRequestFile(): bool
    {
        return is_file($this->base.'/'.self::REQUEST_FILE);
    }

    /**
     * Remove the request file: always when `$taskUuid` is null, else only when it is that task's request.
     */
    public function removeRequest(?string $taskUuid = null): void
    {
        // The read and the unlink are not atomic; callers only create a new request while no task is open.
        if ($taskUuid !== null && ($this->readRequest()['task_uuid'] ?? null) !== $taskUuid) {
            return;
        }
        if ($this->hasRequestFile()) {
            @unlink($this->base.'/'.self::REQUEST_FILE);
        }
    }

    /**
     * Take the runner lock without waiting.
     *
     * @return resource|null The handle to pass to releaseLock(), or null when another process holds it.
     */
    public static function acquireLock(string $base): mixed
    {
        $base = rtrim($base, '/\\');
        if (is_link($base.'/'.self::DIR)) {
            return null;
        }
        self::ensureDir($base);
        $path = $base.'/'.self::LOCK_FILE;
        if (is_link($path)) {
            return null;
        }
        $created = ! file_exists($path);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        // A link planted between the check and the open: the opened file must be the one at the path.
        $opened = fstat($handle);
        $atPath = @lstat($path);
        if (! is_array($opened) || ! is_array($atPath) || ($atPath['mode'] & 0170000) !== 0100000 || $opened['ino'] !== $atPath['ino'] || $opened['dev'] !== $atPath['dev']) {
            fclose($handle);

            return null;
        }
        if ($created) {
            @chmod($path, 0664);
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    public static function releaseLock(mixed $handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Whether a runner holds the lock now (a shared non-blocking try, released at once).
     *
     * Fails closed: a lock file that exists but cannot be opened counts as held, so a permission fault never
     * makes a live runner look dead. The brief LOCK_SH can make a runner's LOCK_EX|LOCK_NB fail for that
     * minute; the runner simply retries on the next minute.
     */
    public static function lockHeld(string $base): bool
    {
        $path = rtrim($base, '/\\').'/'.self::LOCK_FILE;
        if (! is_file($path)) {
            return false;
        }
        $handle = is_readable($path) ? @fopen($path, 'r') : false;
        if ($handle === false) {
            return true;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $free;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{action: string, task_uuid: string, version: ?string, web_php: string, requested_at: string}|null
     */
    private static function validRequest(array $data): ?array
    {
        $action = $data['action'] ?? null;
        $uuid = $data['task_uuid'] ?? null;
        $version = $data['version'] ?? null;
        $webPhp = $data['web_php'] ?? null;
        $at = $data['requested_at'] ?? null;

        if (($data['format'] ?? null) !== self::FORMAT || ! in_array($action, self::ACTIONS, true)
            || ! is_string($uuid) || preg_match(self::UUID_PATTERN, $uuid) !== 1
            || ! is_string($webPhp) || $webPhp === '' || str_contains($webPhp, "\0")
            || ! is_string($at) || $at === '') {
            return null;
        }
        if ($action === 'restore' ? $version !== null
            : $version !== null && (! is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1)) {
            return null;
        }

        return ['action' => $action, 'task_uuid' => $uuid, 'version' => $version, 'web_php' => $webPhp, 'requested_at' => $at];
    }

    /**
     * @return array<mixed>|null
     */
    private static function readJson(string $path): ?array
    {
        if (! is_file($path) || (int) @filesize($path) > self::REQUEST_MAX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, self::REQUEST_MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > self::REQUEST_MAX_BYTES) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeJson(string $base, string $file, array $data): void
    {
        $base = rtrim($base, '/\\');
        if (is_link($base.'/'.self::DIR)) {
            throw new \RuntimeException('Cannot write '.$file.': the runner folder is a link.');
        }
        self::ensureDir($base);
        $target = $base.'/'.$file;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Cannot encode '.$file.'.');
        }
        $temp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($temp, $json) === false) {
            throw new \RuntimeException('Cannot write '.$file.'.');
        }
        @chmod($temp, 0664);
        if (! @rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException('Cannot write '.$file.'.');
        }
    }

    private static function ensureDir(string $base): void
    {
        $dir = $base.'/'.self::DIR;
        if (! is_dir($dir) && @mkdir($dir, 0775, true)) {
            @chmod($dir, 0775);
        }
    }
}
