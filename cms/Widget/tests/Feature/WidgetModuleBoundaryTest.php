<?php

namespace Cms\Widget\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Facades\Module;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Proves the Widget bounded-context boundary contract fixed by
 * docs/architecture/adr-widget-module-boundary.md (decisions 1-2):
 *
 * - module.json declares the module correctly and requires only "core" and
 *   "content" (the Content range was added with the Content public API,
 *).
 * - Core/root never imports Cms\Widget\* (one-way dependency direction).
 * - The module is present and enabled as a bundled module (modules_statuses.json
 *   turned it on 2026-07-20); the ADR's "disabled until rollout" step is done.
 * - routes/web.php stays empty/reserved.
 * - The declared provider class autoloads via the composer-merge-plugin
 *   PSR-4 registration.
 */
final class WidgetModuleBoundaryTest extends TestCase
{
    public function test_module_json_declares_widget_requiring_only_core_with_existing_providers(): void
    {
        $path = base_path('cms/Widget/module.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Widget', $decoded['name']);
        // Object form: module name => version range. Only Core and Content may be required.
        $requires = $decoded['requires'] ?? null;
        $this->assertIsArray($requires);
        $names = array_keys($requires);
        sort($names);
        $this->assertSame(['content', 'core'], $names);
        $this->assertNotEmpty($decoded['providers'] ?? []);

        foreach ($decoded['providers'] as $providerClass) {
            $this->assertTrue(
                class_exists($providerClass),
                "Declared provider {$providerClass} must autoload (run composer dump-autoload)."
            );
        }
    }

    public function test_core_and_root_app_never_reference_cms_widget_namespace(): void
    {
        $roots = [
            base_path('app'),
            base_path('cms/Core'),
            base_path('routes'),
            base_path('config'),
            base_path('bootstrap'),
        ];

        // Scan for both the raw namespace token and its double-backslash
        // escaped form (as it would literally appear inside a PHP string
        // literal, e.g. 'Cms\\Widget\\...'), so neither a `use` import nor
        // a string-based class reference can slip past this check.
        $needles = ['Cms\\Widget', 'Cms\\\\Widget'];

        $violations = [];
        foreach ($needles as $needle) {
            $violations = array_merge($violations, $this->scanForForbiddenReference($roots, $needle));
        }

        // Not source: bootstrap/cache/modules.php is the generated, git-ignored module manifest cache (it lists every
        // enabled module provider). The one Core test below only reaches the real Widget guard behind class_exists().
        $allowed = [
            base_path('bootstrap/cache/modules.php'),
            base_path('cms/Core/tests/Feature/Modules/ModuleDisableGuardTest.php'),
        ];
        $violations = array_diff($violations, $allowed);

        $this->assertSame([], array_values(array_unique($violations)), 'Core/root files must never import or reference Cms\\Widget.');
    }

    public function test_widget_module_is_present_and_enabled_as_a_bundled_module(): void
    {
        $module = Module::find('Widget');

        $this->assertNotNull($module, 'Widget module must be discoverable by the module system.');
        $this->assertTrue($module->isEnabled(), 'Widget is a bundled module and is enabled in modules_statuses.json.');

        $statusesPath = base_path('modules_statuses.json');
        $this->assertFileExists($statusesPath);

        $statuses = json_decode((string) file_get_contents($statusesPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($statuses['Widget'] ?? false);
    }

    public function test_widget_web_routes_file_loads_and_registers_no_routes(): void
    {
        $path = base_path('cms/Widget/routes/web.php');
        $this->assertFileExists($path);

        $routesBefore = Route::getRoutes()->count();

        require $path;

        $routesAfter = Route::getRoutes()->count();

        $this->assertSame($routesBefore, $routesAfter, 'cms/Widget/routes/web.php must stay empty/reserved.');
    }

    public function test_widget_service_provider_class_autoloads(): void
    {
        $this->assertTrue(
            class_exists(\Cms\Widget\Providers\WidgetServiceProvider::class),
            'Cms\\Widget\\Providers\\WidgetServiceProvider must autoload via the composer-merge-plugin PSR-4 registration in cms/Widget/composer.json.'
        );
    }

    /**
     * @param array<int, string> $roots
     * @return array<int, string>
     */
    private function scanForForbiddenReference(array $roots, string $needle): array
    {
        // Optional performance/noise escape hatch: set SKIP_DOTS=1 in the
        // environment to skip dot-directories (e.g. a stray .git or IDE
        // metadata folder) while scanning. Off by default so the scan stays
        // maximally strict.
        $skipDots = filter_var(getenv('SKIP_DOTS'), FILTER_VALIDATE_BOOLEAN);

        $violations = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $directoryIterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($directoryIterator);

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if ($skipDots && str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '.')) {
                    continue;
                }

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if ($contents !== false && str_contains($contents, $needle)) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        return $violations;
    }
}
