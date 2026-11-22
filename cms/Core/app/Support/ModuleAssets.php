<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Support;

use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * One source of module front-end tags for blades (`@moduleVite('<Module>', [...])`,).
 *
 * A runtime-installed module with a published build (`public/runtime-assets/<Module>/<version>/manifest.json`, version
 * from its `module_statuses` row) is rendered from that build through its own {@see Vite} instance, but only while the
 * runtime copy is the one the site loads: the runtime modules folder holds the module and the release modules folder
 * (`modules.paths.modules`, `cms/`) does not. When a release ships the module again and its copy wins (deploy without
 * `RUNTIME_MODULES_WIN`, F3), the status row and the published build stay behind, and the release copy's
 * entries are rendered. Every other module is rendered from the release's root manifest through the global instance,
 * with entries `cms/<Module>/<entry>`.
 *
 * The global Vite instance is never reconfigured: unlike nwidart's `module_vite()`, the build directory, manifest
 * name and CSP nonce of the page stay as they were. The runtime instance copies the global nonce, so a page with a
 * Content Security Policy keeps working. An unknown entry throws the same {@see \Illuminate\Foundation\ViteException}
 * as Vite does.
 */
final class ModuleAssets
{
    /**
     * Resolved source per module for this request: the published build directory, or null for the release build.
     *
     * @var array<string, string|null>
     */
    private static array $resolved = [];

    /**
     * Tags (script, link and preload) of the given module entries.
     *
     * @param string       $module  module name, e.g. `MailHub`
     * @param list<string> $entries entries relative to the module folder, e.g. `resources/webmail/app.js`
     */
    public static function tags(string $module, array $entries): HtmlString
    {
        $entries = array_values($entries);
        $build = self::runtimeBuild($module);
        if ($build === null) {
            return app(Vite::class)(array_map(fn (string $entry): string => 'cms/' . $module . '/' . ltrim($entry, '/'), $entries));
        }

        $global = app(Vite::class);
        $vite = (new Vite())
            ->useBuildDirectory($build)
            ->useManifestFilename(RuntimeAssets::MANIFEST)
            // A runtime build is never served by the dev server, whatever the page's hot file says.
            ->useHotFile(storage_path('framework/runtime-module-assets.hot'))
            ->withEntryPoints($entries);
        if ($global->cspNonce() !== null) {
            $vite->useCspNonce($global->cspNonce());
        }

        return new HtmlString($vite->toHtml());
    }

    /**
     * Version of the module's published runtime build when the site renders the module from it, else null (release
     * build, no published build, or the release copy wins). The runtime SVG route serves only this version.
     *
     * @param string $module module name, e.g. `MailHub`
     */
    public static function runtimeVersion(string $module): ?string
    {
        $build = self::runtimeBuild($module);

        return $build === null ? null : basename($build);
    }

    /**
     * Forget the per-request resolution (tests and long-running workers).
     */
    public static function flush(): void
    {
        self::$resolved = [];
    }

    /**
     * Build directory (relative to `public/`) of the module's published runtime build, or null for the release build.
     */
    private static function runtimeBuild(string $module): ?string
    {
        if (array_key_exists($module, self::$resolved)) {
            return self::$resolved[$module];
        }
        $build = null;
        $version = preg_match(ReleaseManifest::NAME_PATTERN, $module) === 1 && self::loadedFromRuntime($module)
            ? self::installedVersion($module)
            : null;
        if ($version !== null && preg_match(ReleaseManifest::VERSION_PATTERN, $version) === 1) {
            $root = RuntimeAssets::path();
            if ($root !== null && is_file($root . '/' . $module . '/' . $version . '/' . RuntimeAssets::MANIFEST)) {
                $build = RuntimeAssets::buildDirectory($module, $version);
            }
        }

        return self::$resolved[$module] = $build;
    }

    /**
     * Whether the site loads $module from the runtime modules folder: the release folder wins over a runtime copy of
     * the same name ({@see \App\Support\Modules\ReleaseFirstModuleRepository}), so the runtime copy counts only
     * while the release does not ship the module.
     */
    private static function loadedFromRuntime(string $module): bool
    {
        $release = rtrim((string) config('modules.paths.modules', base_path('cms')), '/');
        if (is_dir($release . '/' . $module)) {
            return false;
        }
        $runtime = RuntimeModules::path();

        return $runtime !== null && is_dir($runtime . '/' . $module);
    }

    /**
     * Installed version recorded for a module, null when none or when the table cannot be read.
     */
    private static function installedVersion(string $module): ?string
    {
        try {
            $connection = config('modules.activators.database.connection');
            $version = DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
                ->table(DatabaseActivator::TABLE)->where('name', $module)->value('version');
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }
}
