<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Modules\Generator\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Nwidart\Modules\Activators\FileActivator;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs `cms:make-module` into a temporary folder and loads the result into the running test application: PSR-4
 * autoloading, nwidart discovery (an extra scan path), its service provider and its migrations. The real module
 * folder and statuses file are never touched; composer is faked.
 */
final class GeneratedModule
{
    private function __construct(
        public readonly string $root,
        public readonly string $module,
        public readonly string $entity,
        public readonly string $mode,
    ) {
    }

    public static function make(string $mode, string $module = 'Blog', string $entity = 'Post', bool $load = true): self
    {
        $parent = sys_get_temp_dir() . '/genmod-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($parent);
        $modulesPath = config('modules.paths.modules');
        $statusesPath = config('modules.activators.file.statuses-file');
        config([
            'modules.paths.modules' => $parent,
            'modules.activators.file.statuses-file' => $parent . '/modules_statuses.json',
        ]);
        Process::fake();

        try {
            $code = Artisan::call('cms:make-module', ['name' => $module, '--entity' => $entity, '--mode' => $mode]);
            if ($code !== 0) {
                throw new RuntimeException('cms:make-module failed: ' . Artisan::output());
            }
        } finally {
            config(['modules.paths.modules' => $modulesPath, 'modules.activators.file.statuses-file' => $statusesPath]);
        }

        $generated = new self($parent . '/' . $module, $module, $entity, $mode);
        if ($load) {
            $generated->load($parent);
        }

        return $generated;
    }

    public function path(string $relative = ''): string
    {
        return $this->root . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /** @return list<string> every generated file, relative to the module folder */
    public function files(): array
    {
        return array_map(
            fn ($file): string => ltrim(str_replace($this->root, '', $file->getPathname()), '/'),
            File::allFiles($this->root),
        );
    }

    /**
     * Turn the module on or off for this test: a temporary statuses file (the real statuses plus this module) and a
     * fresh file activator, so `isEnabled()` answers without touching modules_statuses.json.
     */
    public function enable(bool $enabled = true): void
    {
        $real = (string) config('modules.activators.file.statuses-file');
        $statuses = is_file($real) ? (array) json_decode((string) file_get_contents($real), true) : [];
        $statuses[$this->module] = $enabled;
        $file = dirname($this->root) . '/modules_statuses.json';
        File::put($file, json_encode($statuses));
        config(['modules.activators.file.statuses-file' => $file]);
        app()->instance(ActivatorInterface::class, new FileActivator(app()));
    }

    public function dispose(): void
    {
        File::deleteDirectory(dirname($this->root));
    }

    /**
     * A real module registers its routes before the application's own catch-all routes (`{cate}/{slug}`); a provider
     * registered during a test lands after them, so its routes are moved to the front to match a real boot.
     */
    private function routesFirst(string $namespace): void
    {
        $router = app('router');
        $routes = $router->getRoutes()->getRoutes();
        $mine = array_filter($routes, static fn (Route $route): bool => str_starts_with($route->getActionName(), $namespace));
        $collection = new RouteCollection();
        foreach ([...$mine, ...array_diff_key($routes, $mine)] as $route) {
            $collection->add($route);
        }
        $router->setRoutes($collection);
    }

    private function load(string $parent): void
    {
        $namespace = 'Cms\\' . $this->module . '\\';
        $loader = require base_path('vendor/autoload.php');
        $loader->addPsr4($namespace, $this->root . '/app/', true);
        $loader->addPsr4($namespace . 'Database\\Seeders\\', $this->root . '/database/seeders/', true);
        $loader->addPsr4($namespace . 'Tests\\', $this->root . '/tests/', true);

        // app('modules') is not a shared instance here (container extender), so the extra folder goes in the scan
        // config, which every repository instance reads.
        config([
            'modules.scan.enabled' => true,
            'modules.scan.paths' => array_merge((array) config('modules.scan.paths', []), [$parent]),
        ]);
        app()->register($namespace . 'Providers\\' . $this->module . 'ServiceProvider');
        $this->routesFirst($namespace);
        Artisan::call('migrate', ['--path' => $this->root . '/database/migrations', '--realpath' => true]);
    }
}
