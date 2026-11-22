<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * Autoloader for modules in the runtime modules folder.
 *
 * Composer only knows the release modules (merge plugin, dumped at deploy), so
 * a module that arrives later needs its own loader. Each valid runtime module
 * gets one loader that maps only its own `Cms\<Name>\` psr-4 prefixes and only
 * requires files whose real path stays inside the module folder (a symlink to
 * a file outside is refused). Invalid modules are skipped and remembered for
 * the boot warning in AppServiceProvider.
 *
 * Called from bootstrap/app.php before configuration is loaded; with no
 * runtime folder it performs one is_dir() and returns.
 *
 * A runtime folder carrying the name of a release module is a collision and
 * is ignored (the release copy wins), unless {@see RuntimePrecedence} prefers
 * it (listed in runtime_modules.json, newer, compatible with the release Core,
 * placed by the installer;). A preferred module's loader is prepended,
 * so its classes load before Composer's release copy of the same classes.
 */
final class RuntimeModuleAutoloader
{
    /** Quarantine code of a runtime folder that shadows a release module. */
    public const SHADOWS_RELEASE = 'MODULE_SHADOWS_RELEASE';

    /** @var list<callable> Registered loaders, kept for reset(). */
    private static array $loaders = [];

    /** @var array<string, list<string>> Skipped module name => validation errors. */
    private static array $skipped = [];

    /** @var array<string, array{code: string, reason: string, path: string, providers: list<string>}> Quarantined module name => details. */
    private static array $quarantined = [];

    /** @var list<string> Runtime names that also exist in the release modules folder. */
    private static array $collisions = [];

    /** @var list<string> Release module names whose runtime copy wins (RuntimePrecedence). */
    private static array $preferred = [];

    /** Base path already registered, so a second bootstrap does not stack loaders. */
    private static ?string $registeredBase = null;

    /**
     * Register one loader per valid runtime module in the runtime folder ({@see RuntimeModules::location()}).
     */
    public static function register(string $basePath): void
    {
        $runtimeDir = RuntimeModules::location($basePath);
        if (! is_dir($runtimeDir)) {
            return;
        }
        if (self::$registeredBase === $basePath) {
            return;
        }
        self::$registeredBase = $basePath;

        $releaseDir = $basePath . DIRECTORY_SEPARATOR . 'cms';
        foreach (RuntimeModules::names($runtimeDir) as $name) {
            $dir = $runtimeDir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($releaseDir . DIRECTORY_SEPARATOR . $name)) {
                // RuntimePrecedence already validated the folder and its providers.
                $root = realpath($dir);
                if ($root !== false && RuntimePrecedence::preferRuntime($basePath, $name)) {
                    self::$preferred[] = $name;
                    self::registerModule($root, RuntimeModules::psr4($dir), true);

                    continue;
                }
                self::$collisions[] = $name;
            }

            $errors = RuntimeModules::validate($dir, $name, $releaseDir);
            $root = realpath($dir);
            if ($errors !== [] || $root === false) {
                self::$skipped[$name] = $errors !== [] ? $errors : ['module folder cannot be resolved'];
                self::quarantineInvalid($name, $dir, self::$skipped[$name], $releaseDir);

                continue;
            }

            $psr4 = RuntimeModules::psr4($dir);
            $broken = self::brokenProviders($root, $dir, $psr4);
            if ($broken !== null) {
                self::quarantine($name, $root, $broken);
            }

            self::registerModule($root, $psr4);
        }
    }

    /**
     * Make the release modules folder win over any scan-path copy of the same name.
     *
     * Container extenders survive the later singleton() binding done by the
     * nwidart provider, so the repository and manifest it resolves are the
     * release-first subclasses. Cheap: nothing is resolved here.
     */
    public static function bindReleaseFirst(\Illuminate\Contracts\Container\Container $app): void
    {
        $app->extend(\Nwidart\Modules\Contracts\RepositoryInterface::class, static function ($repository, $app) {
            if ($repository instanceof ReleaseFirstModuleRepository) {
                return $repository;
            }

            return new ReleaseFirstModuleRepository($app, $repository->getPath());
        });
        $app->extend(\Nwidart\Modules\ModuleManifest::class, static function ($manifest, $app) {
            return $manifest instanceof ReleaseFirstModuleManifest
                ? $manifest
                : ReleaseFirstModuleManifest::fromContainer($app, (string) $manifest->manifestPath);
        });
    }

    /**
     * Skipped runtime modules and their validation errors.
     *
     * @return array<string, list<string>>
     */
    public static function skipped(): array
    {
        return self::$skipped;
    }

    /**
     * Quarantined runtime modules: valid folders whose provider class cannot be
     * loaded (missing file or missing psr-4 root folder).
     *
     * @return array<string, array{code: string, reason: string, path: string, providers: list<string>}>
     */
    public static function quarantined(): array
    {
        return self::$quarantined;
    }

    /**
     * Runtime module names that also exist as release modules in cms/.
     *
     * @return list<string>
     */
    public static function collisions(): array
    {
        return self::$collisions;
    }

    /**
     * Release module names whose runtime copy is used (RuntimePrecedence,).
     *
     * @return list<string>
     */
    public static function preferred(): array
    {
        return self::$preferred;
    }

    /**
     * Unregister every loader and forget all state (tests only).
     */
    public static function reset(): void
    {
        foreach (self::$loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        self::$loaders = [];
        self::$skipped = [];
        self::$quarantined = [];
        self::$collisions = [];
        self::$preferred = [];
        self::$registeredBase = null;
        RuntimePrecedence::forget();
    }

    /**
     * Register the loader closure for one validated module.
     *
     * @param string $root Real path of the module folder.
     * @param array<string, list<string>> $psr4 Prefix => relative folders.
     * @param bool $prepend Put the loader before Composer's (a preferred runtime copy of a release module).
     */
    private static function registerModule(string $root, array $psr4, bool $prepend = false): void
    {
        // Longest prefix first, as Composer does.
        uksort($psr4, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $loader = static function (string $class) use ($root, $psr4): void {
            foreach ($psr4 as $prefix => $folders) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
                foreach ($folders as $folder) {
                    $file = $root . DIRECTORY_SEPARATOR . ($folder === '' ? '' : $folder . DIRECTORY_SEPARATOR) . $relative;
                    $real = realpath($file);
                    if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) {
                        require $real;

                        return;
                    }
                }
            }
        };

        spl_autoload_register($loader, true, $prepend);
        self::$loaders[] = $loader;
    }

    /**
     * First problem that stops a module provider from loading, or null when all load.
     *
     * @param string $root Real path of the module folder.
     * @param string $dir Module folder as scanned.
     * @param array<string, list<string>> $psr4 Prefix => relative folders.
     * @return array{code: string, reason: string, providers: list<string>}|null
     */
    public static function brokenProviders(string $root, string $dir, array $psr4): ?array
    {
        $module = json_decode((string) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'module.json'), true);
        $providers = [];
        foreach ((array) (is_array($module) ? ($module['providers'] ?? []) : []) as $provider) {
            $providers[] = ltrim((string) $provider, '\\');
        }

        $code = null;
        $reason = null;
        $missing = [];
        foreach ($providers as $provider) {
            $problem = self::providerProblem($root, $psr4, $provider);
            if ($problem !== null) {
                $missing[] = $provider;
                $code ??= $problem[0];
                $reason ??= $problem[1];
            }
        }

        return $missing === [] ? null : ['code' => (string) $code, 'reason' => (string) $reason, 'providers' => $missing];
    }

    /**
     * Why one provider class cannot load; null when its file exists inside the module.
     *
     * @param array<string, list<string>> $psr4 Prefix => relative folders.
     * @return array{0: string, 1: string}|null Machine code and English reason.
     */
    private static function providerProblem(string $root, array $psr4, string $provider): ?array
    {
        $folderMissing = false;
        foreach ($psr4 as $prefix => $folders) {
            if (! str_starts_with($provider, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($provider, strlen($prefix))) . '.php';
            foreach ($folders as $folder) {
                $base = $root . DIRECTORY_SEPARATOR . ($folder === '' ? '' : $folder);
                if (! is_dir($base)) {
                    $folderMissing = true;

                    continue;
                }
                $real = realpath($base . DIRECTORY_SEPARATOR . $relative);
                if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) {
                    return null;
                }
            }
        }

        return $folderMissing
            ? ['AUTOLOAD_ROOT_MISSING', "The autoload folder for provider {$provider} is missing from the module."]
            : ['PROVIDER_CLASS_MISSING', "The provider class {$provider} cannot be loaded from the module files."];
    }

    /**
     * Quarantine a module that failed validation so nwidart cannot fatal on its
     * providers. Only declared providers inside `Cms\<Name>\` that cannot be
     * loaded are aliased. A runtime name that shadows a release module is left
     * alone: its providers belong to the release module.
     *
     * @param list<string> $errors Validation errors.
     */
    private static function quarantineInvalid(string $name, string $dir, array $errors, string $releaseDir): void
    {
        if (is_dir($releaseDir . DIRECTORY_SEPARATOR . $name)) {
            self::quarantineShadow($name, $dir);

            return;
        }

        $module = json_decode((string) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'module.json'), true);
        $namespace = 'Cms\\' . $name . '\\';
        $providers = [];
        foreach ((array) (is_array($module) ? ($module['providers'] ?? []) : []) as $provider) {
            $provider = is_string($provider) ? ltrim($provider, '\\') : '';
            if (str_starts_with($provider, $namespace) && ! class_exists($provider)) {
                $providers[] = $provider;
            }
        }

        self::quarantine($name, realpath($dir) ?: $dir, [
            'code' => 'MODULE_INVALID',
            'reason' => 'The module failed validation: ' . implode('; ', $errors),
            'providers' => $providers,
        ]);
    }

    /**
     * Remember a runtime folder that carries the name of a release module. It is
     * listed as quarantined with its own code but nothing is aliased, and
     * AppServiceProvider never disables it: the name belongs to the release module.
     */
    private static function quarantineShadow(string $name, string $dir): void
    {
        self::$quarantined[$name] = [
            'code' => self::SHADOWS_RELEASE,
            'reason' => "The runtime folder {$name} has the same name as a release module in cms/ and is ignored. Remove or rename the runtime folder.",
            'providers' => [],
            'path' => realpath($dir) ?: $dir,
        ];
    }

    /**
     * Remember a broken module and alias its missing providers to a no-op provider.
     *
     * @param array{code: string, reason: string, providers: list<string>} $broken
     */
    private static function quarantine(string $name, string $root, array $broken): void
    {
        self::$quarantined[$name] = $broken + ['path' => $root];

        $targets = array_flip($broken['providers']);
        $loader = static function (string $class) use ($targets): void {
            if (isset($targets[ltrim($class, '\\')])) {
                class_alias(QuarantinedModuleProvider::class, $class);
            }
        };
        spl_autoload_register($loader);
        self::$loaders[] = $loader;
    }
}
