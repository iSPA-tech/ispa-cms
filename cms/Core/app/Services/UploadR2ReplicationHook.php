<?php

namespace Cms\Core\Services;

class UploadR2ReplicationHook
{
    public function __construct(private R2ObjectTracker $tracker)
    {
    }

    /**
     * Call immediately after a new original or variant image file is written to local disk
     * (crawler download, on-demand variant generation, ...). Fire-and-forget: never throws, and
     * can be disabled via R2_REPLICATION_HOOK_ENABLED without touching any caller. A tracking
     * failure here must never break the write it's piggybacking on — upload:r2-discover is the
     * real safety net for anything this misses.
     */
    public function afterLocalWrite(string $relativePath, ?int $uploadId, string $disk = 'public'): void
    {
        if (!config('filesystems.disks.r2.replication_hook_enabled', true)) {
            return;
        }

        $this->tracker->trackFreshWriteSafely($relativePath, $uploadId, $disk);
    }
}
