<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Jobs;

use Exception;
use Cms\Core\Models\Upload;
use Cms\Core\Services\OrphanUploadPurgeService;

class RemoveDeletedUploadFilesJob // implements ShouldQueue
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
        try {
            $deletedFiles = Upload::onlyTrashed()
                ->where('deleted_at', '<=', now()->subDays(30))
                ->get();

            if ($deletedFiles->isEmpty()) {
                logger('RemoveDeletedUploadFilesJob no file to delete');
                return;
            }

            foreach ($deletedFiles as $deletedFile) {
                app(OrphanUploadPurgeService::class)->purgeIfOrphan($deletedFile);
            }
        } catch (Exception $e) {
            logger('RemoveDeletedUploadFilesJob', ['error' => $e->getMessage()]);
        }
    }
}
