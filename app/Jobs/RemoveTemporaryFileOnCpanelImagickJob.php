<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Jobs;

class RemoveTemporaryFileOnCpanelImagickJob // implements ShouldQueue
{
    /** @var bool */
    public bool $isDiscovery = false;


//    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $count = 0;
        //  List all the files start with `magick--` in the directory name .cagefs/tmp on the server
        $files = glob(__DIR__ . '/../../.cagefs/tmp/magick-*');
        //  Loop through the files and remove it if it is older than 1 day
        foreach (array_slice($files, 0, 10000) as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

    }
}
