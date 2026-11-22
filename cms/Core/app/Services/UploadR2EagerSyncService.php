<?php

namespace Cms\Core\Services;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Support\Facades\Storage;

class UploadR2EagerSyncService
{
    public function __construct(
        private R2ObjectTracker $tracker,
        private UploadR2SyncService $syncService,
    ) {
    }

    /**
     * Synchronously track + upload the given local files to R2, smallest file first, right
     * inside the upload request itself (not deferred to a background job) — the smallest preset
     * (e.g. an admin-panel thumbnail) lands on R2 soonest. Keep local copies: only
     * upload:r2-evict-local may delete them after hash verification and retention.
     */
    public function syncEagerly(?int $uploadId, array $relativePaths, string $disk = 'public'): void
    {
        $ordered = collect($relativePaths)
            ->unique()
            ->filter(fn (string $path) => Storage::disk($disk)->exists($path))
            ->sortBy(fn (string $path) => Storage::disk($disk)->size($path))
            ->values();

        if ($ordered->isEmpty()) {
            return;
        }

        foreach ($ordered as $relativePath) {
            $this->tracker->trackFreshWriteSafely($relativePath, $uploadId, $disk);

            $object = UploadR2Object::where('relative_path', $relativePath)->first();

            if ($object && !in_array($object->status, ['synced', 'verified'], true)) {
                $this->syncService->syncObject($object);
            }
        }
    }
}
