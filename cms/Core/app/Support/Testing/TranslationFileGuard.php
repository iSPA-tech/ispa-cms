<?php

namespace Cms\Core\Support\Testing;

/**
 * Refuses writes to tracked translation files while the test suite runs.
 *
 * Only tracked files are protected; untracked scratch trees stay writable. Every code path that writes into a translation location (lang packs, translate seeders,
 * exported translate seeders, admin i18n sidecars) calls assertWritable() first. Outside of
 * tests the guard does nothing, so production behaviour is unchanged.
 */
class TranslationFileGuard
{
    /**
     * Relative path patterns (from the project root) that identify protected translation files.
     *
     * @var array<int, string>
     */
    private const PROTECTED_PATTERNS = [
        '#^lang/#',
        '#^cms/[^/]+/lang/#',
        '#^projects/[^/]+/lang/#',
        '#^resources/lang/#',
        '#(^|/)database/seeders/sql/translate[^/]*\.php$#',
        '#(^|/)database/seeders/exported/translates[^/]*\.php$#',
        '#^cms/[^/]+/admin/i18n/#',
    ];

    /**
     * Root used instead of the project root (tests of the guard itself only).
     */
    private static ?string $rootOverride = null;

    /**
     * Throw when a test run is about to write a protected translation file.
     *
     * @param string $path Absolute or relative target path.
     *
     * @return void
     *
     * @throws TranslationFileWriteRefused
     */
    public static function assertWritable(string $path): void
    {
        if (! function_exists('app') || ! app()->runningUnitTests()) {
            return;
        }

        $root = self::root();
        $absolute = self::resolve($path);

        if (! str_starts_with($absolute, $root . '/')) {
            return;
        }

        $relative = substr($absolute, strlen($root) + 1);

        if (str_starts_with($relative, 'storage/framework/testing/')) {
            return;
        }

        if (self::isProtected($relative) && self::isTrackedOrUnknown($root, $relative)) {
            throw TranslationFileWriteRefused::forPath($path);
        }
    }

    /**
     * Tell whether a project-relative path is a protected translation file.
     *
     * @param string $relativePath Path relative to the project root.
     *
     * @return bool
     */
    public static function isProtected(string $relativePath): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if (str_starts_with($relativePath, './')) {
            $relativePath = substr($relativePath, 2);
        }

        foreach (self::PROTECTED_PATTERNS as $pattern) {
            if (preg_match($pattern, $relativePath) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell whether git tracks a file. Throwaway fixture trees that merely look like translation
     * locations (for example below bootstrap/testing) are untracked and stay writable. When git
     * cannot answer, the file counts as tracked so the guard fails closed.
     *
     * @param string $root     Real project root.
     * @param string $relative Path relative to the root.
     *
     * @return bool
     */
    private static function isTrackedOrUnknown(string $root, string $relative): bool
    {
        if (! function_exists('proc_open')) {
            return true;
        }

        $process = @proc_open(
            ['git', '-C', $root, 'ls-files', '-z', '--', $relative],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return true;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || $output === false) {
            return true;
        }

        return $output !== '';
    }

    /**
     * Override the project root used for relative-path resolution (guard tests only).
     *
     * @param string|null $root Directory to treat as the project root, or null to restore the default.
     *
     * @return void
     */
    public static function useRoot(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * Resolve the real project root. It is derived from this file, not base_path(), because
     * commands may swap the application base path for a run.
     *
     * @return string
     */
    private static function root(): string
    {
        $root = self::$rootOverride ?? dirname(__DIR__, 5);

        return rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    }

    /**
     * Turn a possibly relative, possibly not-yet-existing path into a normalized real path.
     *
     * @param string $path Target path.
     *
     * @return string
     */
    private static function resolve(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (! str_starts_with($path, '/') && preg_match('#^[A-Za-z]:/#', $path) !== 1) {
            $path = rtrim(str_replace('\\', '/', (string) getcwd()), '/') . '/' . $path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $tail = [];
        while ($segments !== []) {
            $real = realpath('/' . implode('/', $segments));
            if ($real !== false) {
                return rtrim(str_replace('\\', '/', $real), '/') . ($tail === [] ? '' : '/' . implode('/', $tail));
            }
            array_unshift($tail, array_pop($segments));
        }

        return '/' . implode('/', $tail);
    }
}
