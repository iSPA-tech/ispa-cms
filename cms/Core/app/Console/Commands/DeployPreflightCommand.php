<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use App\Support\Modules\ModuleStatus;
use App\Support\Modules\ModuleDeploymentPreflightRegistry;
use App\Services\Tracking\TrackingBufferedCounterStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Verifies that all required deployment artifacts exist before the application is activated.
 *
 * Checks for the presence of exported seed files (widgets, widget_themes, widget_inputs, settings)
 * and generated i18n runtime packs for each supported locale (en, vi). Outputs a clear OK/MISSING
 * status line for each artifact along with the generation command to run if something is absent.
 * Returns FAILURE when any artifact is missing so CI pipelines can block the deployment.
 *
 * Usage: php artisan cms:deploy-preflight
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DeployPreflightCommand extends Command
{
    private const BUILDABLE_VITE_INPUT_PATTERN = '/\.(css|js|jsx|ts|tsx|vue)$/i';
    private const PREPROCESSOR_VITE_INPUT_PATTERN = '/\.(scss|sass|less|styl)$/i';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:deploy-preflight';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that required deployment artifacts exist before activation';

    /**
     * Execute the console command.
     *
     * Iterates over all required artifact paths, reports their presence, and returns
     * FAILURE if any are missing.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(
        ModuleDeploymentPreflightRegistry $moduleRegistry,
        TrackingBufferedCounterStore $trackingStore,
    ): int
    {
        $this->info('Starting deployment preflight check...');

        $requirements = [
            'Widgets' => [
                'path' => base_path('cms/Core/database/seeders/exported/widgets.php'),
                'command' => 'php artisan cms:export-db widgets',
            ],
            'Widget Themes' => [
                'path' => base_path('cms/Core/database/seeders/exported/widget_themes.php'),
                'command' => 'php artisan cms:export-db widget_themes',
            ],
            'Widget Inputs' => [
                'path' => base_path('cms/Core/database/seeders/exported/widget_inputs.php'),
                'command' => 'php artisan cms:export-db widget_inputs',
            ],
            'Settings' => [
                'path' => base_path('cms/Core/database/seeders/exported/settings.php'),
                'command' => 'php artisan cms:export-db settings',
            ],
        ];

        // i18n checks
        // We assume 'vi' is a required locale as per user description
        $version = config('ispa-cms.app_version');
        $requirements['i18n Root'] = [
            'path' => storage_path("app/public/i18n/{$version}"),
            'command' => 'php artisan cms:translation-generate',
        ];

        $requirements['i18n Admin Manifest (en)'] = [
            'path' => storage_path("app/public/i18n/{$version}/adm/en/manifest.json"),
            'command' => 'php artisan cms:translation-generate',
        ];

        $requirements['i18n Admin Manifest (vi)'] = [
            'path' => storage_path("app/public/i18n/{$version}/adm/vi/manifest.json"),
            'command' => 'php artisan cms:translation-generate',
        ];

        $requirements['i18n Admin Merged (en)'] = [
            'path' => storage_path("app/public/i18n/{$version}/adm/en.json"),
            'command' => 'php artisan cms:translation-generate',
        ];

        $requirements['i18n Admin Merged (vi)'] = [
            'path' => storage_path("app/public/i18n/{$version}/adm/vi.json"),
            'command' => 'php artisan cms:translation-generate',
        ];

        $missing = [];

        if (config('tracking.buffer.enabled') && !$trackingStore->usesDedicatedDatabase()) {
            $missing[] = [
                'label' => 'Tracking Redis must use a dedicated Redis database',
                'path' => '',
                'command' => 'Set TRACKING_REDIS_COUNTER_CONNECTION=tracking and REDIS_TRACKING_DB',
            ];
            $this->error('TRACKING PREFLIGHT FAILED: Tracking Redis must use a dedicated Redis database');
        }

        foreach ($requirements as $label => $req) {
            $path = $req['path'];
            if (!File::exists($path)) {
                $missing[] = [
                    'label' => $label,
                    'path' => $path,
                    'command' => $req['command'],
                ];
                $this->error("MISSING: {$label}");
                $this->line("  Expected: {$path}");
                $this->line("  Generate with: {$req['command']}");
            } else {
                $this->info("OK: {$label}");
            }
        }

        foreach ($this->missingViteManifestEntries() as $entry => $details) {
            $missing[] = [
                'label' => "Vite manifest entry: {$entry}",
                'path' => $details['manifest'],
                'command' => 'pnpm build',
            ];
            $this->error("MISSING: Vite manifest entry: {$entry}");
            $this->line("  Expected in: {$details['manifest']}");
            $this->line("  Source: {$details['source']}");
            $this->line('  Generate with: pnpm build');
        }

        // Generic root deployment-preflight registry: has no Widget (or any
        // module) knowledge here, and preserves current behavior exactly
        // when nothing is registered. A module (e.g. cms/Widget) registers
        // its own checker (registry compatibility, managed-disable
        // preflight, etc.) via ModuleDeploymentPreflightRegistry::register()
        // without Core ever importing that module's classes.
        foreach ($moduleRegistry->run() as $checkerName => $issues) {
            foreach ($issues as $issue) {
                $missing[] = [
                    'label' => "{$checkerName}: {$issue}",
                    'path' => '',
                    'command' => '',
                ];
                $this->error("MODULE PREFLIGHT FAILED: {$checkerName}: {$issue}");
            }
        }

        // Hub widget catalog files must pass the verify gate. Files only: the deploy database syncs just this
        // project's themes, and rendering every theme there is left to local and CI runs of the full command.
        if ($this->call('cms:widget-catalog-verify', ['--files-only' => true]) !== self::SUCCESS) {
            $missing[] = [
                'label' => 'Widget catalog verification',
                'path' => '',
                'command' => 'php artisan cms:widget-catalog-verify',
            ];
        }

        if (!empty($missing)) {
            $this->newLine();
            $this->error('Preflight check FAILED. Missing ' . count($missing) . ' artifacts.');
            return self::FAILURE;
        }

        $this->info('Preflight check PASSED.');
        return self::SUCCESS;
    }

    /**
     * Return required Vite entries that are absent from the root build manifest.
     *
     * @return array<string, array{manifest: string, source: string}>
     */
    protected function missingViteManifestEntries(): array
    {
        $requiredEntries = $this->requiredViteManifestEntries();

        if ($requiredEntries === []) {
            return [];
        }

        $manifestPath = $this->deploymentBasePath('public/build/manifest.json');

        if (!File::exists($manifestPath)) {
            return collect($requiredEntries)
                ->mapWithKeys(fn (string $source, string $entry): array => [
                    $entry => [
                        'manifest' => $manifestPath,
                        'source' => $source,
                    ],
                ])
                ->all();
        }

        $manifest = json_decode((string) File::get($manifestPath), true);

        if (! is_array($manifest)) {
            return collect($requiredEntries)
                ->mapWithKeys(fn (string $source, string $entry): array => [
                    $entry => [
                        'manifest' => $manifestPath,
                        'source' => $source,
                    ],
                ])
                ->all();
        }

        $missing = [];

        foreach ($requiredEntries as $entry => $source) {
            if (! array_key_exists($entry, $manifest)) {
                $missing[$entry] = [
                    'manifest' => $manifestPath,
                    'source' => $source,
                ];
            }
        }

        return $missing;
    }

    /**
     * Resolve all root-manifest entries that deployment must prove before activation.
     *
     * @return array<string, string>
     */
    protected function requiredViteManifestEntries(): array
    {
        $entries = [];

        // Declared entries (module.json frontend.entries) are always proven; the vite.config.js scan stays opt-in.
        foreach ($this->enabledModuleNames() as $module) {
            foreach ($this->moduleDeclaredFrontendEntries($module) as $input) {
                $entries[$input] = "cms/{$module}/module.json";
            }
        }

        if ($this->moduleViteConfigManifestChecksEnabled()) {
            foreach ($this->enabledModuleNames() as $module) {
                foreach ($this->moduleViteConfigInputs($module) as $input) {
                    $entries[$input] = "cms/{$module}/vite.config.js";
                }
            }
        }

        foreach ($this->bladeViteInputs() as $input => $source) {
            $entries[$input] = $source;
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected function enabledModuleNames(): array
    {
        if (config('modules.activator') !== 'file') {
            return ModuleStatus::enabledNames();
        }

        // File activator: read the statuses file under the deployment root being checked.
        $path = $this->deploymentBasePath('modules_statuses.json');

        if (! File::exists($path)) {
            return [];
        }

        $modules = json_decode((string) File::get($path), true);

        if (! is_array($modules)) {
            return [];
        }

        $enabled = [];

        foreach ($modules as $module => $active) {
            if ($active && is_string($module) && $module !== '') {
                $enabled[] = $module;
            }
        }

        sort($enabled);

        return $enabled;
    }

    /**
     * Root-manifest entries a module declares in module.json "frontend.entries" (paths under its resources/ folder).
     *
     * @return list<string>
     */
    protected function moduleDeclaredFrontendEntries(string $module): array
    {
        $path = $this->deploymentBasePath("cms/{$module}/module.json");

        if (! File::exists($path)) {
            return [];
        }

        $manifest = json_decode((string) File::get($path), true);
        $declared = is_array($manifest) ? ($manifest['frontend']['entries'] ?? []) : [];

        if (! is_array($declared)) {
            return [];
        }

        return collect($declared)
            ->filter(fn (mixed $input): bool => is_string($input) && $input !== '')
            ->map(fn (string $input): string => $this->normalizeViteManifestEntry("cms/{$module}/{$input}"))
            ->filter(fn (string $input): bool => $this->isBuildableViteInput($input))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function moduleViteConfigInputs(string $module): array
    {
        // Only Game* modules still ship a vite.config.js (out of scope of the module.json frontend migration,).
        if (! str_starts_with($module, 'Game')) {
            return [];
        }

        $configPath = $this->deploymentBasePath("cms/{$module}/vite.config.js");

        if (! File::exists($configPath)) {
            return [];
        }

        $contents = (string) File::get($configPath);
        preg_match_all('/resources\/assets\/[^\'"`\s]+/i', $contents, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $input): string => $this->normalizeViteManifestEntry("cms/{$module}/{$input}"))
            ->filter(fn (string $input): bool => $this->isBuildableViteInput($input))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function bladeViteInputs(): array
    {
        $entries = [];

        foreach ($this->bladeSearchRoots() as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $contents = (string) File::get($file->getPathname());

                preg_match_all('/@vite\s*\((.*?)\)/s', $contents, $calls);

                foreach ($calls[1] ?? [] as $call) {
                    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $call, $quotedInputs);

                    foreach ($quotedInputs[1] ?? [] as $input) {
                        $entry = $this->normalizeViteManifestEntry($input);

                        if ($this->isBuildableViteInput($entry)) {
                            $entries[$entry] = $this->relativeToBasePath($file->getPathname());
                        }
                    }
                }
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected function bladeSearchRoots(): array
    {
        return [
            $this->deploymentBasePath('resources/views'),
            $this->deploymentBasePath('projects'),
            $this->deploymentBasePath('cms'),
        ];
    }

    protected function normalizeViteManifestEntry(string $input): string
    {
        $input = str_replace('\\', '/', trim($input));
        $basePath = str_replace('\\', '/', $this->deploymentBasePath());

        if (str_starts_with($input, $basePath . '/')) {
            $input = substr($input, strlen($basePath) + 1);
        }

        return ltrim($input, '/');
    }

    protected function isBuildableViteInput(string $input): bool
    {
        return ! str_contains($input, '*')
            && (
                preg_match(self::BUILDABLE_VITE_INPUT_PATTERN, $input) === 1
                || (
                    preg_match(self::PREPROCESSOR_VITE_INPUT_PATTERN, $input) === 1
                    && $this->cssPreprocessorInputsEnabled()
                )
            );
    }

    protected function relativeToBasePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $basePath = str_replace('\\', '/', $this->deploymentBasePath());

        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    protected function deploymentBasePath(string $path = ''): string
    {
        return base_path($path);
    }

    protected function cssPreprocessorInputsEnabled(): bool
    {
        return filter_var(
            env('ISPA_VITE_ENABLE_CSS_PREPROCESSORS', env('ISPA_VITE_ENABLE_SASS', false)),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    protected function moduleViteConfigManifestChecksEnabled(): bool
    {
        return filter_var(env('ISPA_DEPLOY_PREFLIGHT_REQUIRE_MODULE_VITE_CONFIGS', false), FILTER_VALIDATE_BOOLEAN);
    }
}
