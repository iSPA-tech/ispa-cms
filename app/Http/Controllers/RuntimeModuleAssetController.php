<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers;

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Cms\Core\Support\ModuleAssets;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the SVG files of a runtime-installed module under `/runtime-assets/<Name>/<version>/<path>.svg` (,
 * F5).
 *
 * {@see RuntimeAssets::publish()} never copies SVG files into the public folder, so the web server finds no static
 * file and hands the request to the application. The file is read from the installed module's signed
 * `frontend-build` folder and sent with `X-Content-Type-Options: nosniff` and a Content Security Policy that allows no
 * script, no request and no form (`sandbox`), so a script inside a package SVG cannot run when the file is opened
 * directly. As an `<img>` or a CSS background the SVG renders as before.
 *
 * Only the version the site renders the module from is served ({@see ModuleAssets::runtimeVersion()}: runtime copy
 * loaded, release copy absent, published build present); anything else answers 404. The route runs without the `web`
 * middleware group: no session and no cookies.
 *
 * @package App\Http\Controllers
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-16)
 */
class RuntimeModuleAssetController extends Controller
{
    /** Content Security Policy of a served SVG: inline styles and data images only, no script, sandboxed. */
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; sandbox";

    /**
     * Send one SVG file of a runtime module.
     *
     * @param string $module  Module name from the URL.
     * @param string $version Published version from the URL.
     * @param string $path    File path inside the build folder (ends in `.svg`, checked by the route).
     */
    public function __invoke(string $module, string $version, string $path): BinaryFileResponse
    {
        abort_unless(
            preg_match(ReleaseManifest::NAME_PATTERN, $module) === 1
                && preg_match(ReleaseManifest::VERSION_PATTERN, $version) === 1
                && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === RuntimeAssets::APP_SERVED_EXTENSION
                && ModuleAssets::runtimeVersion($module) === $version,
            404
        );

        $runtime = RuntimeModules::path();
        $root = $runtime === null ? false : realpath($runtime . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . RuntimeModules::FRONTEND_BUILD_DIR);
        // `{path}` may hold `../` segments: resolve first, then require the file to sit inside the build folder.
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $path);
        abort_if($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => 'image/svg+xml',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            // The version is part of the URL and Vite file names carry a hash, so the file never changes in place.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
