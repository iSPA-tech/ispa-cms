<?php

namespace Cms\Core\Services\CoreUpgrade;

/**
 * Compares the files of this site with the installed core manifest (spec 5.5 integrity rule).
 *
 * Only files listed in `checked` count. Replaced paths (public/build, vendor, ...) and anything the manifest does
 * not list (own projects, own modules, .env, storage) are never looked at.
 */
final class CoreIntegrity
{
    /**
     * Checked files whose sha256 differs or that are missing.
     *
     * @return list<string> Sorted posix paths.
     */
    public function modified(CoreManifest $manifest, ?string $base = null): array
    {
        $root = $this->root($base);
        $modified = [];
        foreach ($manifest->checked as $path => $hash) {
            $file = $root.'/'.$path;
            if (! is_file($file) || is_link($file) || hash_file('sha256', $file) !== $hash) {
                $modified[] = $path;
            }
        }
        sort($modified, SORT_STRING);

        return $modified;
    }

    /**
     * True when the site has dependencies the shipped vendor/ lacks: composer.lock differs from the manifest,
     * or a cms/<Module>/composer.json exists that the manifest does not list.
     */
    public function customDependencies(CoreManifest $manifest, ?string $base = null): bool
    {
        $root = $this->root($base);
        $lock = $root.'/composer.lock';
        $current = is_file($lock) ? (string) hash_file('sha256', $lock) : '';
        if ($current !== $manifest->composerLockSha256) {
            return true;
        }
        foreach (glob($root.'/cms/*/composer.json') ?: [] as $file) {
            $relative = 'cms/'.basename(dirname($file)).'/composer.json';
            if (! isset($manifest->checked[$relative])) {
                return true;
            }
        }

        return false;
    }

    private function root(?string $base): string
    {
        return rtrim($base ?? base_path(), '/\\');
    }
}
