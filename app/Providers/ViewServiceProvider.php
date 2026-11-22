<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use Illuminate\Support\Str;
use App\Composers\AppComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $folderName = Str::lower((string) config('ispa-cms.folder_name', 'Default'));
        // The active project's views answer to its namespace (`default::` included): module pages extend
        // config('ispa-cms.folder') . 'layout.index'. Default used to be skipped, so on a fresh install every Content
        // page failed with "No hint path defined for [default]" (2026-11-19).
        $folderPath = config('ispa-cms.folder_path')
            ?: ($folderName === 'default' ? 'projects/Default/views' : 'resources/views');
        View::addNamespace($folderName, base_path($folderPath));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Boot-time location resolution assumes php-fpm per-request boot;
        // primary per-request resolution is the generation-keyed
        // WidgetTemplatePathResolver, which stays fresh across a switch.
        // Registered only when present: view:cache walks every registered
        // location with Finder and throws on a missing directory, while a
        // missing dir contributes nothing to runtime lookups anyway.
        $widgetLocation = $this->resolveDefaultWidgetGenerationLocation();
        if (is_dir($widgetLocation)) {
            View::addLocation($widgetLocation);
        }
        // Using class based composers...
        View::composer('*', AppComposer::class);
    }

    /**
     * Resolve the view location backing the Default project's legacy widget
     * artifacts: the active generation's Default project set when one has
     * been published, otherwise the characterized legacy directory (safe
     * when nothing has been published yet — Gate 0 Task 2 migration path).
     */
    private function resolveDefaultWidgetGenerationLocation(): string
    {
        $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
        $entry = $manifest?->projectEntry('Default');

        if ($entry !== null) {
            $generationId = (string) ($entry['generation_id'] ?? '');
            $slug = (string) ($entry['slug'] ?? '');

            if ($generationId !== '' && $slug !== '') {
                $path = LegacyWidgetArtifactSetPublisher::generationPath($generationId) . '/' . $slug;
                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        return storage_path('app/generated_widgets/default');
    }
}
