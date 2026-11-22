<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Upload;
use Cms\Core\Services\R2ObjectTracker;
use Cms\Core\Services\StorageReconciliationService;
use Cms\Core\Support\TrackingOutcome;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DiscoverUploadStorageObjectsCommand extends Command
{
    protected $signature = 'upload:r2-discover {--recent-hours=} {--full} {--root=}';
    protected $description = 'Discover physical upload files and track them for R2 sync — the real independent safety net, not dependent on any hook having fired';

    public function handle(R2ObjectTracker $tracker): int
    {
        // Lock TTL sized for a potentially long full scan across ~210K files — a short lock would
        // expire mid-scan. The fingerprint fast-path (in discoverExistingFileOrFail) makes repeat
        // runs cheap, so an occasional lock timeout on a slow first pass just means the next
        // scheduled run continues where this one left off.
        $lock = Cache::lock('upload:r2-discover', 7200);
        if (!$lock->get()) {
            $this->error('Another r2-discover run is already in progress.');
            return self::FAILURE;
        }

        try {
            $roots = $this->option('root') ? [$this->option('root')] : StorageReconciliationService::KNOWN_UPLOAD_ROOTS;
            $mtimeCutoff = $this->option('full') ? null : now()->subHours((int) ($this->option('recent-hours') ?: 24))->timestamp;

            return $this->walkRoots($tracker, $roots, $mtimeCutoff);
        } finally {
            $lock->release();
        }
    }

    private function walkRoots(R2ObjectTracker $tracker, array $roots, ?int $mtimeCutoff): int
    {
        // Preloaded ONCE — replaces what would otherwise be one query per file (~210K queries for
        // a full scan). Keyed by the FULL relative file path (path . '/' . name), matching
        // Upload::getTargetFile() — `path` alone is only the directory, not the full path. Built
        // in PHP rather than a DB-specific CONCAT() so this stays portable across mysql and the
        // sqlite test connection.
        $pathToId = Upload::query()
            ->get(['id', 'path', 'name'])
            ->mapWithKeys(fn (Upload $u) => [$u->path . '/' . $u->name => $u->id]);
        $tracked = 0;
        $failures = 0;

        foreach ($roots as $root) {
            $diskRoot = Storage::disk('public')->path($root);
            if (!is_dir($diskRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($diskRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                // Filter by the FILE's actual mtime, not `uploads.updated_at` — a newly generated
                // variant never touches its parent upload row, so filtering by the DB timestamp
                // would silently miss it.
                if ($mtimeCutoff !== null && $fileInfo->getMTime() < $mtimeCutoff) {
                    continue;
                }

                $relative = $root . '/' . ltrim(str_replace($diskRoot, '', $fileInfo->getPathname()), '/');
                $originalPath = preg_replace('/\.\d+x\d+\.webp$/', '', $relative);
                $uploadId = $pathToId[$originalPath] ?? null; // null is fine — a genuine filesystem orphan

                try {
                    $outcome = $tracker->discoverExistingFileOrFail($relative, $uploadId, 'public');
                    if ($outcome === TrackingOutcome::Tracked) {
                        $tracked++;
                    }
                } catch (\Throwable $e) {
                    $failures++;
                    Log::error('upload:r2-discover: tracking write failed', ['relative_path' => $relative, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info("Tracked/updated: {$tracked}, Failures: {$failures}");
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
