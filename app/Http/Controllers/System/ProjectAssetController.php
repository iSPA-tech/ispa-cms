<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve a file out of public/projects/{project_folder}.
 *
 * Exists as a class because a closure route cannot be serialized, and one
 * uncacheable route is enough to make `route:cache` fail for the whole app.
 *
 * The project folder is read from config at request time rather than baked
 * into the route action, so the behaviour survives route caching unchanged —
 * the route pattern itself still carries the folder name, exactly as before.
 *
 * @package App\Http\Controllers\System
 * @author  iSPA Team
 * @since   8.1.0 (2026-08-03)
 */
class ProjectAssetController extends Controller
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        $folder = (string) config('ispa-cms.folder_name', 'Default');
        $root = public_path('projects/' . $folder);

        // Resolve before comparing: `{path}` matches `.*`, so `../` segments
        // would otherwise walk out of the project folder.
        $file = realpath($root . '/' . $path);
        $rootReal = realpath($root);

        abort_if(
            $file === false || $rootReal === false || ! str_starts_with($file, $rootReal . DIRECTORY_SEPARATOR),
            404
        );

        abort_unless(is_file($file), 404);

        return response()->file($file);
    }
}
