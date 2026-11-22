<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandAlias;

class LinkPublicFolderCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $signature = 'cms:link-folder';
    protected $description = 'Link the public folder to the specified template folder';

    public function handle(): int
    {
        $templateFolderName = config('ispa-cms.folder_name');
        $this->info("Linking public folder to {$templateFolderName}");

        $sourceFolder = base_path("projects/{$templateFolderName}/public");
        $targetFolder = public_path("projects/{$templateFolderName}");

        // Ensure source exists (create placeholder if missing)
        if (!File::exists($sourceFolder)) {
            $projectBase = dirname($sourceFolder);

            if (!File::exists($projectBase)) {
                $this->error("Project directory {$projectBase} does not exist");
                return CommandAlias::FAILURE;
            }

            $this->warn("Source folder {$sourceFolder} missing. Creating empty directory.");
            File::makeDirectory($sourceFolder, 0755, true);
        }

        // Remove existing folder or link
        if (is_link($targetFolder)) {
            $this->warn("Removing existing symlink: $targetFolder");
            unlink($targetFolder);
        } else if (is_dir($targetFolder)) {
            $this->warn("Removing existing directory: $targetFolder");
            File::deleteDirectory($targetFolder);
        } else if (file_exists($targetFolder)) {
            $this->warn("Removing existing file: $targetFolder");
            unlink($targetFolder);
        }

        // Ensure parent directory exists before symlink
        $parent = dirname($targetFolder);
        if (!File::isDirectory($parent)) {
            $this->info("Creating parent directory: $parent");
            File::makeDirectory($parent, 0755, true);
        }

        // Create the symlink
        symlink($sourceFolder, $targetFolder);
        $this->info("Linked: $targetFolder → $sourceFolder");

        // Also link storage
        $this->call('storage:link', ['--force' => true]);

        return CommandAlias::SUCCESS;
    }
}
