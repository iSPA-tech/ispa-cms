<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Blade;

class TemplatePreviewService
{
    /** @var bool */
    public bool $isDiscovery = false;

    protected string $basePath;

    public function __construct()
    {
        $this->basePath = base_path('projects/' . config('ispa-cms.folder_name') . '/dev/template');
    }

    public function render(string $relativePath = 'index'): string
    {
        // Register template directory for includes/layouts
        View::addLocation($this->basePath);
        // Remove the .blade in the path
        $relativePath = str_replace('.blade', '', $relativePath);

        $targetFile = $this->basePath . '/' . trim($relativePath, '/') . '.blade.php';

        if (!File::exists($targetFile)) {
            abort(404, 'Template file not found: ' . $targetFile);
        }

        $content = File::get($targetFile);

        // Blade::render will auto-detect @extends, @section, etc.
        return Blade::render($content, []);
    }
}
