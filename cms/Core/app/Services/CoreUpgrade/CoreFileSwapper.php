<?php

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Services\Modules\Packages\SafeExtractor;
use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Plans, backs up, applies and reverts the file part of a core upgrade under one injected base path.
 *
 * Only paths of the installed or the new manifest's `checked` set, the `replaced` set, and the removed set
 * (installed checked minus new checked) are ever touched, and never a per-site path ({@see self::isProtected()}).
 * For a deploy-origin installed manifest ({@see CoreManifest::fromDeploy()}) the plan is narrower: see plan().
 * Replaced folders (`vendor`, `public/build`, `public/iPanel`) are swapped by renaming the old one to
 * `<path>.old-<stamp>`, so they can be put back by a rename and are deleted only after the health check.
 */
final class CoreFileSwapper
{
    /** Per-site files an upgrade never writes, replaces or removes, even when a manifest lists them. */
    public const PROTECTED_FILES = ['.env', 'config/setting.php', 'modules_statuses.json', 'public/iPanel/config.js'];

    /** Per-site folders an upgrade never touches. */
    public const PROTECTED_PREFIXES = ['storage', 'runtime', 'bootstrap/cache', 'public/storage', 'node_modules', '.git', '.ispa-runner', 'runtime-modules'];

    /**
     * Files a CI deploy owns even when the deploy hook's manifest lists them: VERSION keys the deploy's admin build and
     * its i18n paths (a new VERSION breaks every later admin rebuild until the next deploy), the composer files and
     * the runtime module list belong to the deploy's vendor/ and module set.
     */
    public const DEPLOY_OWNED_FILES = ['VERSION', 'composer.json', 'composer.lock', 'runtime_modules.json'];

    /** Files kept from the old folder when a replaced folder is swapped. */
    private const KEEP_IN_REPLACED = ['public/iPanel' => ['config.js']];

    private readonly string $base;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/\\');
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * True for a path an upgrade must leave alone.
     */
    public static function isProtected(string $path): bool
    {
        $path = trim($path, '/');
        if (in_array($path, self::PROTECTED_FILES, true)) {
            return true;
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return preg_match('#^projects/(?!Default(/|$))[^/]+#', $path) === 1;
    }

    /**
     * True for a relative path under the base an upgrade may touch: no leading `/`, no empty, `.` or `..` segment,
     * no NUL or backslash, and not protected. Paths read back from the state file or a backup tar must pass this.
     */
    public static function isSafeRelative(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return ! self::isProtected($path);
    }

    /**
     * Whether every entry of a backup tar is a regular file or folder at a safe relative path
     * ({@see self::isSafeRelative()}). Reads the ustar headers itself, so nothing is extracted before the check;
     * links, devices, pax headers and truncated archives are refused.
     */
    public static function tarIsSafe(string $tarPath): bool
    {
        $handle = @fopen($tarPath, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $longName = null;
            while (true) {
                $block = fread($handle, 512);
                if (! is_string($block) || strlen($block) < 512) {
                    return false;
                }
                if (trim($block, "\0") === '') {
                    return true;
                }
                $size = (int) octdec(trim(substr($block, 124, 12), "\0 "));
                $padded = (int) ceil($size / 512) * 512;
                $type = $block[156];
                $name = rtrim(substr($block, 0, 100), "\0");
                if (substr($block, 257, 5) === 'ustar' && ($prefix = rtrim(substr($block, 345, 155), "\0")) !== '') {
                    $name = $prefix.'/'.$name;
                }
                if ($type === 'L') {
                    $data = $size > 0 && $size <= 4096 ? fread($handle, $padded) : false;
                    if (! is_string($data) || strlen($data) < $padded) {
                        return false;
                    }
                    $longName = rtrim(substr($data, 0, $size), "\0");

                    continue;
                }
                if ($longName !== null) {
                    $name = $longName;
                    $longName = null;
                }
                if (! in_array($type, ['0', "\0", '5'], true) || ! self::isSafeRelative(rtrim($name, '/'))) {
                    return false;
                }
                if ($padded > 0 && fseek($handle, $padded, SEEK_CUR) !== 0) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * What the upgrade will do.
     *
     * @return array{write: list<string>, remove: list<string>, replace: list<string>, skipped: list<string>}
     */
    public function plan(CoreManifest $installed, CoreManifest $new, string $staging, bool $skipVendor): array
    {
        // A CI-deployed release (deploy-origin manifest): only paths inside the package are written, an existing site
        // file only when the generated manifest proves it came from a core build, a missing one only inside a top
        // folder the core build already owns here; vendor/ and the built assets belong to the deploy.
        $deploy = $installed->fromDeploy();
        $ownedTops = [];
        foreach (array_keys($installed->checked) as $path) {
            if (str_contains((string) $path, '/')) {
                $ownedTops[explode('/', (string) $path)[0]] = true;
            }
        }
        $write = [];
        $skipped = [];
        foreach ($new->checked as $path => $hash) {
            $path = (string) $path;
            if (self::isProtected($path) || ($deploy && in_array($path, self::DEPLOY_OWNED_FILES, true))) {
                $skipped[] = $path;

                continue;
            }
            if ($deploy && ! isset($installed->checked[$path])) {
                $present = file_exists($this->base.'/'.$path) || is_link($this->base.'/'.$path);
                if ($present || ! str_contains($path, '/') || ! isset($ownedTops[explode('/', $path)[0]])) {
                    $skipped[] = $path;

                    continue;
                }
            }
            $current = $this->base.'/'.$path;
            if (! is_file($current) || is_link($current) || hash_file('sha256', $current) !== $hash) {
                $write[] = $path;
            }
        }
        $remove = [];
        foreach (array_diff_key($installed->checked, $new->checked) as $path => $_) {
            $path = (string) $path;
            if ($deploy && in_array($path, self::DEPLOY_OWNED_FILES, true)) {
                continue;
            }
            if (! self::isProtected($path) && (is_file($this->base.'/'.$path) || is_link($this->base.'/'.$path))) {
                $remove[] = $path;
            }
        }
        $replace = [];
        foreach ($deploy ? [] : $new->replaced as $path) {
            if (self::isProtected($path) || ($skipVendor && $path === 'vendor')) {
                continue;
            }
            if (file_exists($staging.'/'.$path)) {
                $replace[] = $path;
            }
        }
        sort($write, SORT_STRING);
        sort($remove, SORT_STRING);

        return ['write' => $write, 'remove' => $remove, 'replace' => $replace, 'skipped' => $skipped];
    }

    /**
     * Copy every file the plan will change or remove (and the installed manifest) into a tar.
     *
     * @param array{write: list<string>, remove: list<string>, replace: list<string>, skipped: list<string>} $plan
     * @param list<string> $extra Further files to keep, relative to the base.
     */
    public function backup(array $plan, string $tarPath, array $extra = []): int
    {
        if (! is_dir(dirname($tarPath))) {
            mkdir(dirname($tarPath), 0700, true);
        }
        if (is_file($tarPath)) {
            unlink($tarPath);
        }
        $tar = new PharData($tarPath);
        $tar->addFromString('.core-upgrade-backup', "iSPA CMS core upgrade backup\n");
        $files = array_merge($plan['write'], $plan['remove'], [CoreManifest::FILE], $extra);
        foreach ($plan['replace'] as $path) {
            if (is_file($this->base.'/'.$path)) {
                $files[] = $path;
            }
        }
        $count = 0;
        foreach (array_unique($files) as $path) {
            $file = $this->base.'/'.$path;
            if (is_file($file) && ! is_link($file)) {
                $tar->addFile($file, $path);
                $count++;
            }
        }
        unset($tar);

        return $count;
    }

    /**
     * Put the backed-up files back, over whatever is there.
     */
    public function restoreTar(string $tarPath): void
    {
        $tar = new PharData($tarPath);
        $tar->extractTo($this->base, null, true);
        @unlink($this->base.'/.core-upgrade-backup');
    }

    /**
     * Apply the plan from staging. Replaced folders are renamed aside with the given stamp.
     *
     * Staging lives in the runner-only `.ispa-runner/`. As a second line of defence every written file is hashed again
     * after it is copied into the code tree and must match `$hashes` (the verified new manifest), and a replaced folder
     * or file must be owned by this process.
     *
     * @param array{write: list<string>, remove: list<string>, replace: list<string>, skipped: list<string>} $plan
     * @param array<string, string> $hashes path => sha256 of the new manifest's `checked` set
     *
     * @throws CoreUpgradeException CORE_FILE_HASH or CORE_STAGING when staging was changed after verification.
     *
     * @return list<array{path: string, old: string}> The renamed-aside folders (for the state file and the cleanup).
     */
    public function apply(array $plan, string $staging, string $stamp, array $hashes = []): array
    {
        // Every replaced path is checked before anything is written or removed, so a refusal leaves the code as it was.
        $uid = CoreRunnerTick::currentUid($this->base);
        foreach ($plan['replace'] as $path) {
            $source = $staging.'/'.$path;
            if (! CoreRunnerTick::ownedDirectory($source, $uid) && ! CoreRunnerTick::ownedRegularFile($source, $uid)) {
                throw new CoreUpgradeException('CORE_STAGING', 'A staged folder is a link or not owned by the user running the upgrade: '.$path);
            }
        }
        foreach ($plan['write'] as $path) {
            $this->copyFile($staging.'/'.$path, $this->base.'/'.$path, $hashes[$path] ?? null);
        }
        foreach ($plan['remove'] as $path) {
            @unlink($this->base.'/'.$path);
            $this->pruneEmptyParents(dirname($path));
        }
        $aside = [];
        foreach ($plan['replace'] as $path) {
            $source = $staging.'/'.$path;
            $target = $this->base.'/'.$path;
            if (is_dir($source) && ! is_link($source)) {
                $old = $path.'.old-'.$stamp;
                $had = is_dir($target) || is_link($target);
                if ($had) {
                    rename($target, $this->base.'/'.$old);
                    $aside[] = ['path' => $path, 'old' => $old];
                }
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                rename($source, $target);
                foreach (self::KEEP_IN_REPLACED[$path] ?? [] as $keep) {
                    if ($had && is_file($this->base.'/'.$old.'/'.$keep)) {
                        copy($this->base.'/'.$old.'/'.$keep, $target.'/'.$keep);
                    }
                }
            } else {
                $this->copyFile($source, $target);
            }
        }

        return $aside;
    }

    /**
     * Revert what apply() did: renamed-aside folders go back, files added by the upgrade go away, the tar is restored.
     *
     * @param list<array{path: string, old: string}> $aside
     * @param list<string> $added Files that did not exist before the upgrade.
     */
    public function revert(?string $tarPath, array $aside, array $added): void
    {
        foreach ($aside as $item) {
            $old = $this->base.'/'.$item['old'];
            if (is_dir($old) || is_link($old)) {
                SafeExtractor::removeTree($this->base.'/'.$item['path'], $this->base);
                rename($old, $this->base.'/'.$item['path']);
            }
        }
        foreach ($added as $path) {
            if (! self::isProtected($path)) {
                @unlink($this->base.'/'.$path);
                $this->pruneEmptyParents(dirname($path));
            }
        }
        if ($tarPath !== null && is_file($tarPath)) {
            $this->restoreTar($tarPath);
        }
    }

    /**
     * Delete the renamed-aside folders once the upgrade is healthy.
     *
     * @param list<array{path: string, old: string}> $aside
     */
    public function dropAside(array $aside): void
    {
        foreach ($aside as $item) {
            SafeExtractor::removeTree($this->base.'/'.$item['old'], $this->base);
        }
    }

    /**
     * Copy a tree and keep symlinks as symlinks (used to start a new release folder).
     *
     * @param list<string> $linkInstead Top-level names that become a symlink to the source's own path (shared data kept in place).
     * @param list<string> $skip        Top-level names left out (the runner folder of the old release).
     */
    public static function copyRelease(string $from, string $to, array $linkInstead = [], array $skip = []): void
    {
        mkdir($to, 0755, true);
        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if (in_array($name, $skip, true)) {
                continue;
            }
            $target = $to.'/'.$name;
            if ($entry->isLink()) {
                symlink((string) readlink($entry->getPathname()), $target);
            } elseif (in_array($name, $linkInstead, true)) {
                symlink($entry->getPathname(), $target);
            } elseif ($entry->isDir()) {
                self::copyTree($entry->getPathname(), $target);
            } else {
                copy($entry->getPathname(), $target);
                @chmod($target, $entry->getPerms() & 0777);
            }
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $entry) {
            $relative = substr($entry->getPathname(), strlen($from) + 1);
            $target = $to.'/'.$relative;
            if ($entry->isLink()) {
                symlink((string) readlink($entry->getPathname()), $target);
            } elseif ($entry->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                copy($entry->getPathname(), $target);
                @chmod($target, $entry->getPerms() & 0777);
            }
        }
    }

    private function copyFile(string $source, string $target, ?string $hash = null): void
    {
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        $temp = $target.'.core-new';
        copy($source, $temp);
        // Hash the copy, not the staged file: the copy sits in the code tree the web user cannot write.
        if ($hash !== null && ! hash_equals($hash, (string) hash_file('sha256', $temp))) {
            @unlink($temp);
            throw new CoreUpgradeException('CORE_FILE_HASH', 'A staged file changed after the package was verified: '.substr($target, strlen($this->base) + 1));
        }
        @chmod($temp, fileperms($source) & 0777);
        if (is_link($target)) {
            unlink($target);
        }
        rename($temp, $target);
    }

    private function pruneEmptyParents(string $relative): void
    {
        while ($relative !== '' && $relative !== '.' && $relative !== '/') {
            $dir = $this->base.'/'.$relative;
            if (! is_dir($dir) || is_link($dir) || (new FilesystemIterator($dir))->valid()) {
                return;
            }
            @rmdir($dir);
            $relative = dirname($relative);
        }
    }
}
