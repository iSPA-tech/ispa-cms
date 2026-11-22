<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Display the current iSPA CMS CalVer version and module versions.
 */
class CmsVersionCommand extends Command
{
    /** @var string */
    protected $signature = 'cms:version
        {--json : Output version info as JSON}';

    /** @var string */
    protected $description = 'Display the current iSPA CMS version (CalVer)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $versionFile = base_path('VERSION');

        if (! File::exists($versionFile)) {
            $this->error('VERSION file not found at project root.');

            return self::FAILURE;
        }

        $version = trim(File::get($versionFile));

        if ($this->option('json')) {
            $this->line(json_encode([
                'version' => $version,
                'format' => 'CalVer (YYYY.MM.NN)',
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("iSPA CMS v{$version}");
        $this->line("  Format:  CalVer (YYYY.MM.NN)");
        $this->line("  PHP:     " . PHP_VERSION);
        $this->line("  Laravel: " . app()->version());

        return self::SUCCESS;
    }
}
