<?php

namespace Cms\Core\Services;

use Cms\Core\Support\TrackingOutcome;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class R2ObjectTracker
{
    public function __construct(private UploadR2ObjectRepository $repository)
    {
    }

    /**
     * For request-path hooks ONLY (ImageVariantService::generate(), the crawler's download path),
     * called immediately after a file write completes. ALWAYS hashes — never uses the discovery
     * fast-path, because we know a write JUST happened and cannot trust size+mtime alone to detect
     * it: two writes to the same path within the filesystem's mtime resolution window (as coarse
     * as 1 second on some filesystems) could produce identical size+mtime, and the fast path would
     * wrongly report "unchanged," silently dropping the second write's real content. Never throws.
     */
    public function trackFreshWriteSafely(string $relativePath, ?int $uploadId, string $sourceDisk = 'public'): TrackingOutcome
    {
        try {
            $absolute = Storage::disk($sourceDisk)->path($relativePath);
            if (!is_file($absolute)) {
                return TrackingOutcome::MissingSource;
            }
            $hash = hash_file('sha256', $absolute); // always — no fast-path here
            return $this->recordFingerprint($relativePath, $uploadId, $sourceDisk, filesize($absolute), filemtime($absolute), $hash);
        } catch (Throwable $e) {
            Log::warning('R2ObjectTracker: fresh-write tracking failed; periodic discovery will retry', [
                'relative_path' => $relativePath, 'upload_id' => $uploadId, 'error' => $e->getMessage(),
            ]);
            return TrackingOutcome::Failed; // distinct from MissingSource — the file exists, the tracking WRITE failed
        }
    }

    /**
     * For periodic discovery (incremental and full scans) ONLY — doesn't know whether any given
     * file actually changed, so it's safe and far cheaper to use the size+mtime fast path here
     * (unlike trackFreshWriteSafely, which is called right after a KNOWN write and must not trust
     * the filesystem's mtime resolution). Throws on real failure — discovery commands must catch
     * this themselves, count failures, and exit non-zero; never call this from a request-path hook.
     */
    public function discoverExistingFileOrFail(string $relativePath, ?int $uploadId, string $sourceDisk = 'public'): TrackingOutcome
    {
        $absolute = Storage::disk($sourceDisk)->path($relativePath);
        if (!is_file($absolute)) {
            return TrackingOutcome::MissingSource;
        }

        $currentSize = filesize($absolute);
        $currentMtime = filemtime($absolute);
        $existing = $this->repository->findByPath($relativePath);

        if ($existing
            && (int) $existing->source_size === $currentSize
            && $existing->source_mtime?->timestamp === $currentMtime) {
            return TrackingOutcome::Unchanged; // fast-path — safe here, never in trackFreshWriteSafely()
        }

        $hash = hash_file('sha256', $absolute);
        return $this->recordFingerprint($relativePath, $uploadId, $sourceDisk, $currentSize, $currentMtime, $hash);
    }

    private function recordFingerprint(string $relativePath, ?int $uploadId, string $sourceDisk, int $size, int $mtime, string $hash): TrackingOutcome
    {
        $existing = $this->repository->findByPath($relativePath);

        if (!$existing) {
            $this->repository->create(
                $relativePath, $uploadId, $sourceDisk, $size, $mtime, $hash,
                kind: preg_match('/\.\d+x\d+\.webp$/', $relativePath) ? 'variant' : 'original'
            );
            return TrackingOutcome::Tracked;
        }

        if ($existing->status === 'deleting') {
            return TrackingOutcome::Unchanged;
        }

        $contentChanged = $existing->source_sha256 !== $hash;
        $wasEverSynced = in_array($existing->status, ['synced', 'verified'], true);

        $this->repository->updateFingerprint($existing, $uploadId, $sourceDisk, $size, $mtime, $hash, $contentChanged);

        if ($contentChanged && $wasEverSynced) {
            Log::warning('R2ObjectTracker: content changed at an already-synced path — quarantined (status=content_changed), NOT auto-resynced. Review via upload:r2-approve-change.', [
                'relative_path' => $relativePath,
                'previous_sha256' => $existing->source_sha256,
                'new_sha256' => $hash,
            ]);
            return TrackingOutcome::Quarantined;
        }

        return TrackingOutcome::Tracked;
    }
}
