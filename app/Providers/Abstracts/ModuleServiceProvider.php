<?php

namespace App\Providers\Abstracts;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;

abstract class ModuleServiceProvider extends ServiceProvider
{
    use PathNamespace;

    abstract protected function getModuleName(): string;

    abstract protected function getModuleNameLower(): string;

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->getModuleName(), 'database/migrations'));

        // Đăng ký repository bindings khi application booted
        $this->registerRepositoryBindings();
    }

    /**
     * Register repository bindings from config file
     */
    protected function registerRepositoryBindings(): void
    {
        $this->app->booted(function () {
            $moduleNameLower = $this->getModuleNameLower();
            $bindings = (array)config("{$moduleNameLower}.bindings", []);

            foreach ($bindings as $interface => $concrete) {
                if ($this->app->bound($interface)) {
                    continue;
                }
                $this->app->singleton($interface, $concrete);
            }
        });
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerModuleProviders();
    }

    /**
     * Register module-specific service providers.
     * Override this method in child classes.
     */
    protected function registerModuleProviders(): void
    {
        // Default implementation - override in child classes
    }

    /**
     * Register commands in the format of Command::class
     * Override this method in child classes.
     */
    protected function registerCommands(): void
    {
        // Default implementation - override in child classes
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $nameLower = $this->getModuleNameLower();
        $langPath = resource_path('lang/modules/' . $nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->getModuleName(), 'lang'), $nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->getModuleName(), 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $name = $this->getModuleName();
        $nameLower = $this->getModuleNameLower();
        $configPath = module_path($name, config('modules.paths.generator.config.path'));

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $config = str_replace($configPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
                    $segments = explode('.', $nameLower . '.' . $config_key);

                    // Remove duplicated adjacent segments
                    $normalized = [];
                    foreach ($segments as $segment) {
                        if (end($normalized) !== $segment) {
                            $normalized[] = $segment;
                        }
                    }

                    $key = ($config === 'config.php') ? $nameLower : implode('.', $normalized);

                    $this->publishes([$file->getPathname() => config_path($config)], 'config');
                    $this->mergeConfigFrom($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Merge config from the given path recursively.
     */
    protected function mergeConfigFrom($path, $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $existing = config($key, []);
        $module_config = require $path;

        config([$key => array_replace_recursive($existing, $module_config)]);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $name = $this->getModuleName();
        $nameLower = $this->getModuleNameLower();
        $viewPath = resource_path('views/modules/' . $nameLower);
        $sourcePath = module_path($name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $nameLower . '-module-views']);

        // Some modules ship no resources/views. view:cache walks every
        // registered path with Finder and throws on a missing directory,
        // while a missing dir contributes nothing to runtime lookups.
        $viewSourcePaths = array_merge(
            $this->getPublishableViewPaths(),
            is_dir($sourcePath) ? [$sourcePath] : [],
        );

        if ($viewSourcePaths !== []) {
            $this->loadViewsFrom($viewSourcePaths, $nameLower);
        }

        Blade::componentNamespace(config('modules.namespace') . '\\' . $name . '\\View\\Components', $nameLower);
    }

    /**
     * Get the override view paths searched before the module's own views.
     *
     * The active project's `projects/<Folder>/views/modules/<module>` comes first, so a project replaces
     * a module template by adding a file with the same relative name; it never re-declares the module route
     * for that. App-wide overrides under `resources/views/modules/<module>` follow.
     */
    private function getPublishableViewPaths(): array
    {
        $nameLower = $this->getModuleNameLower();
        $paths = [];

        $folder = (string) config('ispa-cms.folder_name', 'Default');
        $projectPath = base_path('projects/' . $folder . '/views/modules/' . $nameLower);
        if ($folder !== '' && $folder !== 'Default' && is_dir($projectPath)) {
            $paths[] = $projectPath;
        }

        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $nameLower)) {
                $paths[] = $path . '/modules/' . $nameLower;
            }
        }

        return $paths;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
