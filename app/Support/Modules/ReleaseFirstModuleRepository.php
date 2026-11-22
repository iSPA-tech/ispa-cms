<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Str;
use Nwidart\Modules\Laravel\LaravelFileRepository;

/**
 * Module repository where the release modules folder always wins.
 *
 * nwidart keys scanned modules by lower-cased name and lets the last scan path
 * overwrite earlier ones, so a runtime folder carrying a release module's name
 * would replace it (path, views, config, routes). This repository rescans the
 * release folder after the parent scan and puts the release entry back.
 *
 * Exception: when {@see RuntimePrecedence} prefers the runtime copy
 * of a release module (listed in runtime_modules.json, newer, compatible with
 * the release Core, placed by the installer), the runtime entry is used.
 */
class ReleaseFirstModuleRepository extends LaravelFileRepository
{
    /** @var array<string, \Nwidart\Modules\Module> Resolved modules, kept like the parent cache. */
    private static array $resolved = [];

    /**
     * Scan all module paths; a module found in the release folder is never replaced by another path.
     *
     * @return array<string, \Nwidart\Modules\Module>
     */
    public function scan(): array
    {
        if (self::$resolved !== [] && ! $this->app->runningUnitTests()) {
            return self::$resolved;
        }

        // The parent's scan, except that a module.json that is not a JSON object with a name is skipped instead of
        // stopping the boot: a broken runtime copy must leave the release copy running.
        $modules = [];
        foreach ($this->getScanPaths() as $path) {
            foreach ($this->getFiles()->glob("{$path}/module.json") ?: [] as $manifest) {
                $name = self::manifestName($manifest);
                if ($name !== null) {
                    $modules[strtolower($name)] = $this->createModule($this->app, $name, dirname($manifest));
                }
            }
        }

        $pattern = Str::finish($this->getPath(), '/*') . '/module.json';
        foreach ($this->getFiles()->glob($pattern) ?: [] as $manifest) {
            $name = self::manifestName($manifest);
            if (! is_string($name) || $name === '') {
                continue;
            }
            $key = strtolower($name);
            $directory = dirname($manifest);
            if ($name === basename($directory) && RuntimePrecedence::preferRuntime($this->app->basePath(), $name)) {
                $runtime = RuntimeModules::location($this->app->basePath()) . DIRECTORY_SEPARATOR . $name;
                if (! isset($modules[$key]) || $modules[$key]->getPath() !== $runtime) {
                    $modules[$key] = $this->createModule($this->app, $name, $runtime);
                }

                continue;
            }
            if (isset($modules[$key]) && $modules[$key]->getPath() !== $directory) {
                $modules[$key] = $this->createModule($this->app, $name, $directory);
            }
        }

        return self::$resolved = $modules;
    }

    /**
     * The `name` of a module.json, or null when the file is not a JSON object with a non-empty name.
     */
    private static function manifestName(string $manifest): ?string
    {
        $json = json_decode((string) @file_get_contents($manifest), true);
        $name = is_array($json) ? ($json['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Drop the parent's per-process module list and this repository's static scan cache.
     *
     * A long-lived queue worker calls this after placing a module so the next lookup scans the folders again.
     */
    public function resetModules(): static
    {
        self::$resolved = [];

        return parent::resetModules();
    }
}
